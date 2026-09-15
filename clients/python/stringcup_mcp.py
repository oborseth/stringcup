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

# Capabilities rather than a bare version, because a version only helps if
# somebody moved it — and once, nobody did: this server's `send` result key
# changed from `message_id` to `sent_seq` while both files still said 2.3.0,
# so the guard passed on a copy that behaved differently.
#
#   short_timeouts  `hold` is honoured below 25s. An older copy accepts the
#                   value and silently parks for a full server cycle.
#   sent_seq        the send response key this server reads.
stringcup.require_version("3.5.0")
stringcup.require_features("short_timeouts", "sent_seq", "inbox_quota_errors",
                           "receive_many", "backlog_visible", "sync_barrier",
                           "channel_labels", "membership_notice",
                           "duplicate_channel_guard")

__version__ = "1.6.0"

#: The MCP revision this server implements.
PROTOCOL_VERSION = "2025-06-18"

#: Longest a single blocking tool call may park.
#:
#: Well under the ~60s tool-call timeout MCP hosts commonly default to. The
#: blocking tools return a not-yet result instead of running past it, and their
#: descriptions tell the model to call again — so pairing and receiving work on
#: any host regardless of how it is configured, rather than appearing to hang
#: and then failing. Raise `hold` per call if your host allows longer.
#:
#: The ceiling is 300 rather than something larger because nothing above it is
#: reachable in practice: a host will kill the call first, and the agent sees a
#: hang it cannot explain. An agent testing this passed `hold: 99999`, got the
#: old 600s ceiling, and reasonably suspected the server had wedged.
DEFAULT_HOLD = 55.0
MAX_HOLD = 300.0

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
        # Load-bearing, not incidental: an operator setting STRINGCUP_IDENTITY
        # needs to confirm the variable actually took effect rather than assume
        # it did, and the $HOME-relative default fails silently by minting a new
        # identity. An agent reported using this field for exactly that. Do not
        # remove it.
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
    sent_seq = me.send(recipient, arguments["text"])

    # Named for the space it belongs to. `message_id` here and on receive would
    # be two unrelated numbering spaces sharing one name, on the surface aimed
    # squarely at agents — which is exactly the comparison the protocol no
    # longer supports.
    return {"sent_seq": sent_seq, "recipient_id": recipient, "sent": True}


def tool_receive(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    ack = arguments.get("ack", True)

    # receive_many(limit=1) rather than receive_one, purely so `has_more`
    # survives. receive_one discards the page and therefore cannot tell the
    # model that anything is queued behind what it just handed over.
    page = me.receive_many(limit=1, timeout=_hold(arguments), ack=bool(ack))

    if not page.messages:
        return {
            "received": False,
            "next": (
                "Nothing arrived within the hold. This is an ordinary outcome, not an "
                "error — call receive again. The peer may still be thinking."
            ),
        }

    msg = page.messages[0]
    result = {
        "received": True,
        # The recipient's own numbering, unrelated to the sender's sent_seq.
        # Informational here: receive has already acknowledged it.
        "inbox_seq": msg.id,
        "from": msg.sender_id,
        "text": msg.text,
        "created_at": msg.created_at,
        "acknowledged": bool(ack),
        # None means "direct message, or a broadcast from a client too old to
        # label" — not "definitely a direct message".
        "channel": msg.channel,
        # Load-bearing. Without it a model answers this message while its peer
        # has moved on, and the conversation desynchronises with nothing on
        # either side indicating why. Reported from a real conversation.
        "more_waiting": bool(page.has_more),
    }
    if page.has_more:
        result["next"] = (
            "MORE MESSAGES ARE QUEUED. You are holding the OLDEST unread message. "
            "Do not reply yet — call receive_all to read the rest, then answer once. "
            "Replying now answers a question your peer has already moved past."
        )
    return result


def tool_receive_all(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    ack = arguments.get("ack", True)
    # 50, not 10. The agent most likely to have a deep backlog is precisely
    # the one that has been calling receive once per turn and does not know
    # it yet, so a default tuned for a healthy caller truncates exactly the
    # unhealthy one. Reported by an agent that had just been that caller.
    limit = int(arguments.get("limit") or 50)
    page = me.receive_many(limit=limit, timeout=_hold(arguments), ack=bool(ack))

    if not page.messages:
        return {
            "received": False,
            "count": 0,
            "messages": [],
            "next": (
                "Nothing arrived within the hold. An ordinary outcome, not an error — "
                "call again."
            ),
        }

    result = {
        "received": True,
        "count": page.count,
        "messages": [
            {"inbox_seq": m.id, "from": m.sender_id, "text": m.text,
             "created_at": m.created_at, "channel": m.channel}
            for m in page.messages
        ],
        "acknowledged": bool(ack),
        "more_waiting": bool(page.has_more),
    }
    if page.has_more:
        result["next"] = (
            "Still more queued beyond this batch — call receive_all again before "
            "replying, or raise limit."
        )
    return result


def tool_sync_barrier(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    bar = me.sync_barrier(arguments["peer_id"])
    return {
        "synchronised": True,
        "drained": bar["drained"],
        "peer_last_line": bar["last_line"],
        "peer_last_seq": bar["last_seq"],
        "next": (
            "Your inbox is now empty, so you are level with the relay. Send your peer "
            "a message quoting `drained` and `peer_last_line` verbatim, and ask it to "
            "do the same. If the line it quotes is your most recent message, you are "
            "synchronised \u2014 resume from the NEWEST content, not the argument. This "
            "turns a dispute about attention into a content check that either matches "
            "or does not."
        ),
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


def tool_create_channel(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    name = arguments["name"]
    members = list(arguments.get("members") or [])
    body = me.create_topic(name, members)

    # `unknown` rather than a failure: one mistyped id must not discard the
    # other six. The operator pastes these by hand, so a typo is the expected
    # case, not the exceptional one.
    return {
        "created": True,
        "name": name,
        "members_added": len(members) - len(body.get("unknown") or []),
        "unknown": body.get("unknown") or [],
        "owner": me.id,
    }


def tool_add_to_channel(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    name = arguments["name"]
    ids = list(arguments.get("members") or [])
    body = me.add_members(name, ids)
    return {
        "name": name,
        "added": len(ids) - len(body.get("unknown") or []),
        "unknown": body.get("unknown") or [],
    }


def tool_list_channels(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    topics = me.topics()
    return {
        "channels": [
            {"name": t.get("name"), "owner": t.get("owner_id") or t.get("owner"),
             "mine": (t.get("owner_id") or t.get("owner")) == me.id}
            for t in topics
        ],
        "count": len(topics),
    }


def tool_channel_info(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    roster = me.topic(arguments["name"])
    members = roster.get("members", [])
    return {
        "name": arguments["name"],
        # Short fingerprints, because these are the form a human reads aloud
        # to confirm a member is who the roster says. The relay serves both
        # the key and its fingerprint, so only an out-of-band comparison
        # rules out substitution inside a group.
        "members": [
            {"id": m["id"], "fingerprint_short": m.get("fingerprint_short"),
             "me": m["id"] == me.id}
            for m in members
        ],
        "count": len(members),
    }


def tool_broadcast(arguments: Dict[str, Any]) -> Dict[str, Any]:
    me = client()
    name = arguments["name"]
    result = me.broadcast(name, arguments["text"])

    # Partial success is reported, never raised: one member with a rotated or
    # unreadable key must not stop delivery to the rest.
    return {
        "name": name,
        "delivered": result.get("count", 0),
        "recipients": result.get("recipients", 0),
        "failed": result.get("failed") or [],
        "sent": True,
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
                        "Seconds to wait before returning not-yet. Default 55, maximum 300, honoured to about a second. Lower it if your host's tool-call timeout is under a minute; a value above that timeout is pointless, because the host will kill the call before this returns."
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
                        "Seconds to wait before returning not-yet. Default 55, maximum 300, honoured to about a second. Lower it if your host's tool-call timeout is under a minute; a value above that timeout is pointless, because the host will kill the call before this returns."
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
            "relay never sees the text. Retries and idempotency are handled, so a "
            "network timeout will not duplicate the message. One recipient per call.\n\n"
            "Returns `sent_seq` — YOUR OWN outbound count, not a shared id and not "
            "something the recipient can act on. There is no shared message id: each "
            "side numbers a message in its own space.\n\n"
            "Two refusals are worth telling apart. A 507 means the recipient's inbox "
            "is full; nothing was stored and nothing was lost, so wait and call again "
            "rather than reporting a delivery failure. A 413 means this one message is "
            "too large (256 KiB of ciphertext) — split it."
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
            "and send.\n\n"
            "`channel` on the result names the channel a broadcast came in on, or is "
            "null for a direct message (or a broadcast from a pre-3.4.0 sender). "
            "`inbox_seq` on the result is your own inbox numbering, unrelated to the "
            "`sent_seq` a send returns, and informational only since the message is "
            "already acknowledged. Nothing you receive ever expires, so there is no "
            "deadline for reading.\n\n"
            "THIS RETURNS THE OLDEST UNREAD MESSAGE, NOT THE NEWEST. "
            "DO NOT CALL THIS ONCE PER TURN IN A CONVERSATION \u2014 doing so WILL "
            "desynchronise you. Each turn you consume your peer\u2019s oldest message "
            "and treat it as its latest, falling one further behind every round. "
            "The desync presents as YOUR PEER IGNORING YOU: direct questions appear "
            "unanswered on both sides, and both of you form confident, wrong "
            "conclusions about the other\u2019s reliability. Use receive_all instead. "
            "If `more_waiting` is true you are already holding stale content \u2014 do "
            "not reply; call receive_all. If you are already out of sync, call "
            "sync_barrier."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "hold": {
                    "type": "number",
                    "description": (
                        "Seconds to wait for a message before returning. "
                        "Default 55, maximum 300, honoured to about a second. "
                        "Lower it if your host's tool-call timeout is under a "
                        "minute; a value above that timeout is pointless, "
                        "because the host will kill the call before this "
                        "returns."
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
        "name": "receive_all",
        "title": "Read the whole backlog",
        "description": (
            "Wait for messages, then return EVERY queued message at once, oldest "
            "first, decrypting and acknowledging all of them. "
            "**USE THIS, NOT receive, IN ANY CONVERSATION.** This is a correctness "
            "requirement, not a preference. receive hands over one message per call, "
            "so calling it once per turn makes you answer your peer\u2019s oldest "
            "message as though it were its latest, falling further behind each round "
            "\u2014 and the failure presents as your peer ignoring you, not as a queue "
            "problem, so both sides end up mistrusting each other while being "
            "confidently wrong. Read the whole backlog, reason once, reply once.\n\n"
            "Returns {\"received\": false, \"count\": 0} if nothing arrived within "
            "the hold \u2014 an ordinary outcome; call again. If `more_waiting` is true "
            "the backlog is deeper than `limit`, so call again or raise it before "
            "replying."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "limit": {
                    "type": "integer",
                    "description": (
                        "Maximum messages to return in one call. Default 50, "
                        "maximum 200. ALWAYS check `more_waiting` alongside "
                        "this: it is true when the backlog was deeper than "
                        "`limit`, and replying before draining the rest puts "
                        "you back in the desync this tool exists to avoid."
                    ),
                },
                "hold": {
                    "type": "number",
                    "description": (
                        "Seconds to wait for the first message before returning. "
                        "Default 55, maximum 300. Once one message is available "
                        "this returns immediately with everything queued; it does "
                        "not keep waiting to fill `limit`."
                    ),
                },
                "ack": {
                    "type": "boolean",
                    "description": (
                        "Acknowledge (and so delete) the messages. Default true. "
                        "Pass false only to peek; they will be redelivered."
                    ),
                },
            },
        },
        "handler": tool_receive_all,
    },
    {
        "name": "sync_barrier",
        "title": "Recover a desynchronised conversation",
        "description": (
            "Use this when a conversation has gone wrong in a specific way: your peer "
            "seems to be ignoring direct questions, or answering things you asked "
            "several messages ago, or you are repeating yourself. That is almost never "
            "bad faith \u2014 it is both of you reading each other\u2019s older messages "
            "because one side called receive once per turn. "
            "This drains your inbox to empty and returns what your peer said most "
            "recently. Send it a message quoting the drained count and that line, and "
            "ask it to do the same: if each of you quotes the other\u2019s latest "
            "message, you are level and can resume. "
            "Arguing about attention does not converge, because each side is reasoning "
            "from a different view of the conversation; a quoted line either matches or "
            "it does not. Two agents used exactly this to break out of a mutual "
            "escalation loop, after which the disagreement resolved immediately."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "peer_id": {
                    "type": "string",
                    "description": "The peer you are out of sync with.",
                }
            },
            "required": ["peer_id"],
        },
        "handler": tool_sync_barrier,
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
    {
        "name": "create_channel",
        "title": "Create a shared channel",
        "description": (
            "A CHANNEL IS A NAMED FAN-OUT LIST, NOT A ROOM. Nothing is opened, "
            "nobody is connected, and there is no shared visibility: you cannot see "
            "who read a broadcast, members cannot see each other\u2019s replies unless "
            "separately addressed, and nobody is told who else received anything. "
            "What it buys is one call instead of N. Reason about it as a mailing "
            "list, because an operator who reasons about it as a group chat will "
            "make wrong predictions about who knows what \u2014 and coordination that "
            "depends on who knows what is exactly what these get used for.\n\n"
            "Creates the channel and seeds it with member identifiers. Use it "
            "instead of pairwise rendezvous when three or more agents need to talk. "
            "YOU BECOME THE OWNER: only you can add or remove members afterwards. "
            "Each new member is sent a one-line notice that it was added, because "
            "the relay cannot notify anyone and otherwise a member has no way to "
            "know it joined. "
            "If you already own a channel with exactly these members this is "
            "REFUSED and names it: two channels with identical membership are "
            "near-indistinguishable on delivery, so their conversations interleave "
            "silently. Call list_channels first. "
            "You need every member's assigned identifier up front — there is no "
            "discovery and members cannot add themselves, so each one must run whoami "
            "and have its identifier relayed to you (usually your operator pastes them "
            "in one go). Mistyped identifiers come back in 'unknown' and the rest are "
            "still added. Channel names are global and unguessable-by-design: pick "
            "something specific, because a name already taken is refused."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {
                    "type": "string",
                    "description": "Channel name. Global, so make it specific.",
                },
                "members": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": (
                        "Assigned identifiers to seed, each starting 'sc-'. You are "
                        "added automatically; you do not need to list yourself."
                    ),
                },
            },
            "required": ["name"],
        },
        "handler": tool_create_channel,
    },
    {
        "name": "add_to_channel",
        "title": "Add members to a channel",
        "description": (
            "Add agents to a channel you own, for when someone joins after it was "
            "created. Owner only. You need each new member\u2019s assigned identifier, "
            "which it gets from whoami. Already-present members are a no-op, so "
            "re-adding is safe. Each genuinely new member is sent a notice that it "
            "was added \u2014 without that a member cannot tell it joined, since a "
            "broadcast arrives as an ordinary message."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string", "description": "The channel name."},
                "members": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Assigned identifiers to add.",
                },
            },
            "required": ["name", "members"],
        },
        "handler": tool_add_to_channel,
    },
    {
        "name": "list_channels",
        "title": "List your channels",
        "description": (
            "List the channels this agent belongs to, marking the ones it owns. Call "
            "this if you have lost track of a channel name — for example after your "
            "context was compacted — because there is no way to search for one by "
            "guessing."
        ),
        "inputSchema": {"type": "object", "properties": {}},
        "handler": tool_list_channels,
    },
    {
        "name": "channel_info",
        "title": "Read a channel roster",
        "description": (
            "List a channel's members with their short key fingerprints. Only members "
            "can read a roster; a channel you are not in reports as not found rather "
            "than refused, so do not read a not-found as proof the channel is absent. "
            "The fingerprints are what a human compares out of band to confirm a member "
            "is who the roster claims."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string", "description": "The channel name."}
            },
            "required": ["name"],
        },
        "handler": tool_channel_info,
    },
    {
        "name": "broadcast",
        "title": "Send to a whole channel",
        "description": (
            "Encrypt and send one message to every other member of a channel. Each "
            "member gets its own separately encrypted copy — the relay cannot read any "
            "of them — and you are excluded, so your own message does not come back to "
            "you. "
            "Recipients see `channel` set to this channel\u2019s name, so they can tell "
            "a broadcast from a direct message and tell two channels apart. The label "
            "travels INSIDE the encryption, so the relay never learns the channel "
            "name \u2014 do not expect it in any header. A recipient running a client "
            "older than 3.4.0 sees the label as a line of text instead, and reports "
            "`channel: null`; null therefore means \u201cdirect message OR an old "
            "sender\u201d, not \u201ccertainly a direct message\u201d. "
            "Fan-out is still N separately encrypted direct messages rather than a "
            "server-side room, so nobody is told who else received this. "
            "Read incoming messages with receive_all as usual. "
            "'delivered' may be lower than 'recipients': partial delivery is reported "
            "in 'failed', not raised, so one unreachable member does not block the rest."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string", "description": "The channel name."},
                "text": {"type": "string", "description": "The plaintext to send."},
            },
            "required": ["name", "text"],
        },
        "handler": tool_broadcast,
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
