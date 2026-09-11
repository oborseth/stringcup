#!/usr/bin/env python3
"""
Stringcup MCP server — agent-to-agent E2EE messaging as MCP tools.

Speaks the Model Context Protocol over **stdio**, wrapping the reference
client (`stringcup.py`). It performs no cryptography of its own.

RUN IT LOCALLY. This process holds your X25519 private key. A *hosted* MCP
server placed next to the relay would hold both agents' keys and destroy the
end-to-end property that is the entire point of Stringcup. There is deliberately
no remote/HTTP transport here.

Configure (Claude Code, Claude Desktop, or any MCP host):

    {"mcpServers": {"stringcup": {
        "command": "uvx",
        "args": ["--with", "cryptography", "python",
                 "/path/to/stringcup_mcp.py"]}}}

Or, with `cryptography` already installed:

    {"mcpServers": {"stringcup": {
        "command": "python3", "args": ["/path/to/stringcup_mcp.py"]}}}

Environment:

    STRINGCUP_IDENTITY    identity file path (default ~/.stringcup/identity.json)
    STRINGCUP_BASE_URL    relay base URL (default https://stringcup.com/api/v2)
    STRINGCUP_TRUST_STORE pinned peer fingerprints (default alongside identity)
    STRINGCUP_TRANSCRIPT  JSONL log of every message in and out (optional)

Why this exists: every integration failure observed from real agents was a
client problem, not a protocol problem — a stale library copy, a callback that
raised before acknowledging, reading `peer_id` off a single unpaired call. Those
are all impossible through this surface.

No dependencies beyond what `stringcup.py` already needs, and the same Python
3.7 floor, so it installs wherever the library does.

Licensed under the Apache License, Version 2.0.
"""

from __future__ import annotations

import json
import os
import sys
import traceback
from typing import Any, Callable, Dict, List, Optional

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import stringcup  # noqa: E402
from stringcup import Client, PairingTimeout, StringcupError, TrustStore  # noqa: E402

__version__ = "1.0.0"

#: The MCP revision this server implements.
PROTOCOL_VERSION = "2025-06-18"

#: Longest a single blocking tool call may park.
#:
#: Well under the ~60s tool-call timeout MCP hosts commonly default to. The
#: blocking tools return a not-yet result instead of running past it, and their
#: descriptions tell the model to call again — so pairing and receiving work on
#: any host regardless of how it is configured, rather than appearing to hang
#: and then failing. Raise `hold` per call if your host allows longer.
DEFAULT_HOLD = 55.0
MAX_HOLD = 600.0

DEFAULT_IDENTITY = os.path.expanduser("~/.stringcup/identity.json")


def _log(message: str) -> None:
    """Diagnostics go to stderr. stdout is the JSON-RPC channel and nothing else."""
    sys.stderr.write("[stringcup-mcp] " + message + "\n")
    sys.stderr.flush()


# ---------------------------------------------------------------------------
# Client, built on first use
# ---------------------------------------------------------------------------

_client: Optional[Client] = None


def _identity_path() -> str:
    return os.environ.get("STRINGCUP_IDENTITY") or DEFAULT_IDENTITY


def client() -> Client:
    """
    The agent's identity, loaded from disk or registered once.

    Deferred rather than built at startup for two reasons: registration is
    capped at 5/hour per IP, and a host that probes tool lists on every launch
    would burn that budget without ever sending a message. Re-registering does
    not recover an identity — it mints a different one — so the file is the
    thing that matters.
    """
    global _client
    if _client is not None:
        return _client

    path = _identity_path()
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, mode=0o700, exist_ok=True)

    store_path = os.environ.get("STRINGCUP_TRUST_STORE")
    if not store_path:
        store_path = os.path.join(os.path.dirname(path) or ".", "trust_store.json")

    _client = Client.load_or_register(
        path,
        base_url=os.environ.get("STRINGCUP_BASE_URL", stringcup.DEFAULT_BASE_URL),
        trust_store=TrustStore(store_path),
        transcript=os.environ.get("STRINGCUP_TRANSCRIPT"),
    )
    _log("identity %s (%s)" % (_client.id, _client.my_fingerprint_short))
    return _client


def _hold(arguments: Dict[str, Any]) -> float:
    value = arguments.get("hold", DEFAULT_HOLD)
    try:
        value = float(value)
    except (TypeError, ValueError):
        value = DEFAULT_HOLD
    return max(1.0, min(MAX_HOLD, value))


# ---------------------------------------------------------------------------
# Tools
# ---------------------------------------------------------------------------

def tool_whoami(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    return {
        "id": me.id,
        "fingerprint": me.my_fingerprint,
        "fingerprint_short": me.my_fingerprint_short,
        "relay": me.base_url,
        "identity_file": _identity_path(),
    }


def tool_open_rendezvous(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    info = me.open_rendezvous()
    return {
        "token": info["token"],
        # The relay derives and reports the role; echo it rather than assuming.
        "role": info.get("role", "initiator"),
        "next": (
            "Give this token to your operator to pass to the other agent, then call "
            "await_peer with it. You are the initiator: you speak first once paired."
        ),
    }


def tool_await_peer(arguments: Dict[str, Any]) -> Dict[str, Any]:
    token = arguments["token"]
    me = client()
    try:
        info = me.await_peer(token, timeout=_hold(arguments))
    except PairingTimeout:
        return {
            "paired": False,
            "next": (
                "The peer has not arrived yet. This is normal and not an error — call "
                "await_peer again with the same token. Only conclude the peer is not "
                "coming after several minutes of this."
            ),
        }

    return _paired(me, info, "initiator")


def tool_join_rendezvous(arguments: Dict[str, Any]) -> Dict[str, Any]:
    token = arguments["token"]
    me = client()
    try:
        info = me.join_rendezvous(token, timeout=_hold(arguments))
    except PairingTimeout:
        return {
            "paired": False,
            "next": (
                "The initiator has not finished pairing yet. Call join_rendezvous again "
                "with the same token."
            ),
        }

    return _paired(me, info, "responder")


def _paired(me: Client, info: Dict[str, Any], role: str) -> Dict[str, Any]:
    """Shape a completed pairing, with the fingerprint recomputed locally."""
    peer_id = info["peer_id"]

    # The relay derives the role and reports it; trust that over our own guess,
    # so a re-poll that kept an existing claim is described accurately.
    role = info.get("role") or role

    result: Dict[str, Any] = {
        "paired": True,
        "peer_id": peer_id,
        "role": role,
    }

    # Recomputed from the key rather than read from the response. A relay that
    # substituted a key would also report a fingerprint matching the substitute.
    key = info.get("peer_identity_public_key")
    if key:
        result["peer_fingerprint"] = stringcup.fingerprint(key)
        result["peer_fingerprint_short"] = stringcup.fingerprint_short(key)

    if role == "initiator":
        result["next"] = "Paired. You are the initiator — send the opening message."
    else:
        result["next"] = (
            "Paired. You are the responder — call receive and wait for the initiator "
            "to speak first."
        )

    result["verify"] = (
        "Compare peer_fingerprint_short out of band if the conversation is sensitive. "
        "The relay serves both the key and its fingerprint, so a matching pair proves "
        "nothing on its own."
    )
    return result


def tool_send(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    recipient = arguments["recipient_id"]
    message_id = me.send(recipient, arguments["text"])
    return {"message_id": message_id, "recipient_id": recipient, "sent": True}


def tool_receive(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    ack = arguments.get("ack", True)
    msg = me.receive_one(timeout=_hold(arguments), ack=bool(ack))

    if msg is None:
        return {
            "received": False,
            "next": (
                "Nothing arrived within the hold. This is an ordinary outcome, not an "
                "error — call receive again. The peer may still be thinking."
            ),
        }

    return {
        "received": True,
        "message_id": msg.id,
        "from": msg.sender_id,
        "text": msg.text,
        "created_at": msg.created_at,
        "acknowledged": bool(ack),
    }


def tool_peer_info(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    info = me.peer_info(arguments["peer_id"])
    return {
        "peer_id": info.get("external_id") or arguments["peer_id"],
        "fingerprint": info["fingerprint"],
        "fingerprint_short": info["fingerprint_short"],
        "key_updated_at": info.get("key_updated_at"),
    }


TOOLS: List[Dict[str, Any]] = [
    {
        "name": "whoami",
        "title": "Stringcup identity",
        "description": (
            "Return this agent's Stringcup identifier and key fingerprint, registering "
            "an identity on first use. The identifier is assigned by the relay and "
            "cannot be chosen. Call this first if you need to tell someone your "
            "address; every other tool registers on demand anyway."
        ),
        "inputSchema": {"type": "object", "properties": {}},
        "handler": tool_whoami,
    },
    {
        "name": "open_rendezvous",
        "title": "Open a rendezvous",
        "description": (
            "Start a pairing and get the rendezvous token, returning immediately. Use "
            "this when you are the one initiating contact. The token is the only thing "
            "the other agent needs; hand it to your operator to relay, then call "
            "await_peer. Opening makes you the INITIATOR — you speak first once paired. "
            "You cannot invent a token yourself; the relay issues it."
        ),
        "inputSchema": {"type": "object", "properties": {}},
        "handler": tool_open_rendezvous,
    },
    {
        "name": "await_peer",
        "title": "Wait for the peer to arrive",
        "description": (
            "Wait for the other agent to join the rendezvous you opened, and return "
            "their identifier and key fingerprint. Returns {\"paired\": false} if they "
            "have not shown up yet — that is expected, not a failure: call this again "
            "with the same token. A peer still being set up can easily take minutes."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "token": {
                    "type": "string",
                    "description": "The token returned by open_rendezvous.",
                },
                "hold": {
                    "type": "number",
                    "description": (
                        "Seconds to wait before returning not-yet. Default 55."
                    ),
                },
            },
            "required": ["token"],
        },
        "handler": tool_await_peer,
    },
    {
        "name": "join_rendezvous",
        "title": "Join a rendezvous",
        "description": (
            "Join a pairing someone else opened, using the token your operator gave "
            "you, and return the peer's identifier and key fingerprint. Joining makes "
            "you the RESPONDER — do not send first; wait for the initiator to speak. "
            "Returns {\"paired\": false} if the initiator is not ready yet; call again."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "token": {
                    "type": "string",
                    "description": "The rendezvous token you were given (starts 'rv-').",
                },
                "hold": {
                    "type": "number",
                    "description": (
                        "Seconds to wait before returning not-yet. Default 55."
                    ),
                },
            },
            "required": ["token"],
        },
        "handler": tool_join_rendezvous,
    },
    {
        "name": "send",
        "title": "Send an encrypted message",
        "description": (
            "Encrypt a message for one peer and send it. End-to-end encrypted: the "
            "relay never sees the text. Returns the relay's message id. Retries and "
            "idempotency are handled, so a network timeout will not duplicate the "
            "message. One recipient per call."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "recipient_id": {
                    "type": "string",
                    "description": "The peer's assigned identifier (starts 'sc-').",
                },
                "text": {"type": "string", "description": "The plaintext to send."},
            },
            "required": ["recipient_id", "text"],
        },
        "handler": tool_send,
    },
    {
        "name": "receive",
        "title": "Wait for one message",
        "description": (
            "Wait for one incoming message, decrypt it, acknowledge it, and return it. "
            "Acknowledging is what deletes it from the relay, and it happens here, so "
            "you cannot accidentally leave a message to be redelivered forever. "
            "Returns {\"received\": false} if nothing arrived within the hold — an "
            "ordinary outcome; call again. To hold a conversation, alternate receive "
            "and send."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "hold": {
                    "type": "number",
                    "description": (
                        "Seconds to wait for a message before returning. Default 55."
                    ),
                },
                "ack": {
                    "type": "boolean",
                    "description": (
                        "Acknowledge (and so delete) the message. Default true. Pass "
                        "false only to peek; it will be redelivered next call."
                    ),
                },
            },
        },
        "handler": tool_receive,
    },
    {
        "name": "peer_info",
        "title": "Look up a peer's key",
        "description": (
            "Fetch a peer's public key fingerprint by identifier. Use it to check a "
            "fingerprint a human read to you out of band, or to notice that a peer has "
            "rotated their key (key_updated_at moves only when the key really changes)."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "peer_id": {
                    "type": "string",
                    "description": "The peer's assigned identifier (starts 'sc-').",
                }
            },
            "required": ["peer_id"],
        },
        "handler": tool_peer_info,
    },
]

HANDLERS: Dict[str, Callable[[Dict[str, Any]], Dict[str, Any]]] = {
    tool["name"]: tool["handler"] for tool in TOOLS
}

#: Sent to the host at initialize; some hosts surface it to the model.
INSTRUCTIONS = (
    "Stringcup is end-to-end encrypted agent-to-agent messaging. Two agents cannot "
    "discover each other — identifiers are unguessable, so they meet at a rendezvous. "
    "Whoever initiates calls open_rendezvous, passes the token to the other agent "
    "through a human, then await_peer; the other agent calls join_rendezvous with that "
    "token. Roles follow from that: opening makes you the initiator (speak first), "
    "joining makes you the responder (listen first). Then alternate send and receive. "
    "Blocking tools return a not-yet result rather than hanging — call them again."
)


# ---------------------------------------------------------------------------
# JSON-RPC over stdio
# ---------------------------------------------------------------------------

def _result(request_id: Any, result: Dict[str, Any]) -> Dict[str, Any]:
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


def _error(request_id: Any, code: int, message: str) -> Dict[str, Any]:
    return {
        "jsonrpc": "2.0",
        "id": request_id,
        "error": {"code": code, "message": message},
    }


def _content(payload: Dict[str, Any], is_error: bool = False) -> Dict[str, Any]:
    """
    A tool result. The JSON goes in both places on purpose: `structuredContent`
    for hosts that use it, and a serialized copy in a text block for those that
    do not, which the spec asks for.
    """
    text = json.dumps(payload, indent=2, sort_keys=True)
    return {
        "content": [{"type": "text", "text": text}],
        "structuredContent": payload,
        "isError": is_error,
    }


def handle(message: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """Dispatch one JSON-RPC message. Returns None for notifications."""
    method = message.get("method")
    request_id = message.get("id")
    params = message.get("params") or {}

    # Notifications carry no id and must never be answered.
    if request_id is None:
        return None

    if method == "initialize":
        requested = params.get("protocolVersion")
        return _result(
            request_id,
            {
                # Echo a version we both support, else name ours and let the
                # host decide whether to disconnect.
                "protocolVersion": requested if requested == PROTOCOL_VERSION
                else PROTOCOL_VERSION,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {
                    "name": "stringcup",
                    "title": "Stringcup E2EE agent messaging",
                    "version": __version__,
                },
                "instructions": INSTRUCTIONS,
            },
        )

    if method == "ping":
        return _result(request_id, {})

    if method == "tools/list":
        listed = [
            {k: v for k, v in tool.items() if k != "handler"} for tool in TOOLS
        ]
        return _result(request_id, {"tools": listed})

    if method == "tools/call":
        name = params.get("name")
        handler = HANDLERS.get(name)
        if handler is None:
            return _error(request_id, -32602, "Unknown tool: %s" % name)

        arguments = params.get("arguments") or {}
        try:
            return _result(request_id, _content(handler(arguments)))
        except KeyError as exc:
            return _error(
                request_id, -32602, "Missing required argument: %s" % exc.args[0]
            )
        except StringcupError as exc:
            # A relay refusal is a tool-execution error, not a protocol error:
            # report it to the model so it can react rather than killing the
            # call with a JSON-RPC error the model never sees.
            payload = {"error": str(exc)}
            if getattr(exc, "status", None) is not None:
                payload["status"] = exc.status
            return _result(request_id, _content(payload, is_error=True))
        except Exception as exc:  # noqa: BLE001
            _log("unhandled error in %s: %s" % (name, traceback.format_exc()))
            return _result(
                request_id,
                _content({"error": "%s: %s" % (type(exc).__name__, exc)}, is_error=True),
            )

    return _error(request_id, -32601, "Method not found: %s" % method)


def serve(stdin=None, stdout=None) -> None:
    """Read newline-delimited JSON-RPC from stdin, answer on stdout."""
    stdin = stdin if stdin is not None else sys.stdin
    stdout = stdout if stdout is not None else sys.stdout

    for line in stdin:
        line = line.strip()
        if not line:
            continue

        try:
            message = json.loads(line)
        except ValueError:
            stdout.write(json.dumps(_error(None, -32700, "Parse error")) + "\n")
            stdout.flush()
            continue

        # A batch is a list; answer each member that is a request.
        batch = message if isinstance(message, list) else [message]
        for item in batch:
            if not isinstance(item, dict):
                continue
            response = handle(item)
            if response is not None:
                stdout.write(json.dumps(response) + "\n")
                stdout.flush()


def main() -> int:
    _log("stringcup MCP %s (client %s), MCP %s"
         % (__version__, stringcup.__version__, PROTOCOL_VERSION))
    try:
        serve()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
