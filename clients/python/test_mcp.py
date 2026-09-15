#!/usr/bin/env python3
"""
Tests for the Stringcup MCP server.

Two layers:

  * the JSON-RPC/stdio plumbing, driven as a real subprocess so a stray write
    to stdout — the failure that makes an MCP server silently not load — is
    caught rather than assumed absent;
  * the tool handlers, with the Stringcup client stubbed, so tool shapes are
    checked without touching the relay or spending the 5/hour registration cap.

    python3 test_mcp.py
"""

import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import stringcup  # noqa: E402
import stringcup_mcp as mcp  # noqa: E402

PASS = 0
FAIL = 0


def check(condition, label):
    global PASS, FAIL
    if condition:
        PASS += 1
        print("  \033[32m✓\033[0m %s" % label)
    else:
        FAIL += 1
        print("  \033[31m✗\033[0m %s" % label)


def step(label):
    print("\n\033[1m[STEP] %s\033[0m" % label)


# ---------------------------------------------------------------------------
# Layer 1 — the server as a subprocess
# ---------------------------------------------------------------------------

def rpc(messages):
    """Send raw lines to a fresh server process, return parsed responses."""
    payload = "".join(json.dumps(m) + "\n" if not isinstance(m, str) else m + "\n"
                      for m in messages)
    proc = subprocess.Popen(
        [sys.executable, os.path.join(HERE, "stringcup_mcp.py")],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        universal_newlines=True,
    )
    out, err = proc.communicate(payload, timeout=30)
    responses = [json.loads(line) for line in out.splitlines() if line.strip()]
    return responses, err


def test_handshake():
    step("1. Initialize handshake")

    responses, err = rpc([
        {"jsonrpc": "2.0", "id": 1, "method": "initialize",
         "params": {"protocolVersion": mcp.PROTOCOL_VERSION,
                    "capabilities": {},
                    "clientInfo": {"name": "test", "version": "0"}}},
        {"jsonrpc": "2.0", "method": "notifications/initialized"},
        {"jsonrpc": "2.0", "id": 2, "method": "ping"},
    ])

    check(len(responses) == 2, "Notification drew no response (2 replies for 3 messages)")
    init = responses[0]
    check(init.get("jsonrpc") == "2.0" and init.get("id") == 1, "Envelope echoes id")
    result = init.get("result", {})
    check(result.get("protocolVersion") == mcp.PROTOCOL_VERSION,
          "Echoes the requested protocol version")
    check(result.get("capabilities", {}).get("tools") is not None,
          "Declares the tools capability")
    check(result.get("serverInfo", {}).get("name") == "stringcup", "Names itself")
    check("rendezvous" in result.get("instructions", ""),
          "Instructions explain how peers meet")
    check(responses[1].get("result") == {}, "ping answered with an empty result")
    check("stringcup MCP" in err, "Startup banner went to stderr, not stdout")


def test_version_negotiation():
    step("2. Version negotiation")

    responses, _ = rpc([
        {"jsonrpc": "2.0", "id": 1, "method": "initialize",
         "params": {"protocolVersion": "1999-01-01"}},
    ])
    check(responses[0]["result"]["protocolVersion"] == mcp.PROTOCOL_VERSION,
          "An unsupported client version is answered with ours, not echoed")


def test_tools_list():
    step("3. tools/list")

    responses, _ = rpc([{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}])
    tools = responses[0]["result"]["tools"]

    names = [t["name"] for t in tools]
    expected = ["whoami", "open_rendezvous", "await_peer", "join_rendezvous",
                "send", "receive", "receive_all", "sync_barrier", "peer_info",
                "create_channel", "add_to_channel", "list_channels",
                "channel_info", "broadcast"]
    check(names == expected, "All fourteen tools listed in order: %s" % ", ".join(names))
    check(all("handler" not in t for t in tools),
          "The Python handler is not leaked into the wire schema")
    check(all(t.get("description") for t in tools), "Every tool has a description")
    check(all(t["inputSchema"]["type"] == "object" for t in tools),
          "Every inputSchema is an object schema")

    by_name = {t["name"]: t for t in tools}
    check(by_name["send"]["inputSchema"]["required"] == ["recipient_id", "text"],
          "send requires both recipient and text")
    check("required" not in by_name["receive"]["inputSchema"],
          "receive takes no required argument")
    check("INITIATOR" in by_name["open_rendezvous"]["description"],
          "open_rendezvous states the role it confers")
    check("RESPONDER" in by_name["join_rendezvous"]["description"],
          "join_rendezvous states the role it confers")
    check("again" in by_name["await_peer"]["description"],
          "await_peer tells the model to retry rather than give up")
    check("acknowledg" in by_name["receive"]["description"].lower(),
          "receive documents that it acknowledges for you")
    check("sent_seq" in by_name["send"]["description"],
          "send's description names what it returns")
    check("507" in by_name["send"]["description"],
          "send's description explains a full inbox is retryable")
    for tool in ("await_peer", "join_rendezvous", "receive"):
        desc = by_name[tool]["inputSchema"]["properties"]["hold"]["description"]
        check("300" in desc and "second" in desc,
              "%s documents the hold maximum and its resolution" % tool)


def test_protocol_errors():
    step("4. Malformed input and unknown methods")

    responses, _ = rpc([
        "{not json",
        {"jsonrpc": "2.0", "id": 1, "method": "no/such/method"},
        {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
         "params": {"name": "nope", "arguments": {}}},
    ])

    check(responses[0]["error"]["code"] == -32700, "Bad JSON is a parse error (-32700)")
    check(responses[0]["id"] is None, "Parse error carries a null id")
    check(responses[1]["error"]["code"] == -32601, "Unknown method is -32601")
    check(responses[2]["error"]["code"] == -32602, "Unknown tool is -32602")
    check("nope" in responses[2]["error"]["message"], "Error names the unknown tool")


def test_batch():
    step("5. Batched requests")

    responses, _ = rpc([json.dumps([
        {"jsonrpc": "2.0", "id": 1, "method": "ping"},
        {"jsonrpc": "2.0", "method": "notifications/initialized"},
        {"jsonrpc": "2.0", "id": 2, "method": "ping"},
    ])])
    check(len(responses) == 2, "A batch of three yields two replies")
    check([r["id"] for r in responses] == [1, 2], "Both ids answered")


def test_stdout_is_clean():
    step("6. stdout carries nothing but JSON-RPC")

    responses, err = rpc([
        {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}},
        {"jsonrpc": "2.0", "id": 2, "method": "tools/list"},
    ])
    check(len(responses) == 2, "Every stdout line parsed as JSON")
    check(all(r.get("jsonrpc") == "2.0" for r in responses),
          "Every line is a JSON-RPC envelope")


# ---------------------------------------------------------------------------
# Layer 2 — tool handlers against a stubbed client
# ---------------------------------------------------------------------------

class FakeClient:
    """Enough of stringcup.Client to exercise the handlers."""

    PUB = "PUZUOXTdF0tX3drdiA+1+W2n8MgkSKIVf/YSX8Kv8Ww="

    def __init__(self):
        self.id = "sc-aaaaaaaaaaaaaaaaaaaaaaaa"
        self.base_url = "https://example.test/api/v2"
        self.my_fingerprint = stringcup.fingerprint(self.PUB)
        self.my_fingerprint_short = stringcup.fingerprint_short(self.PUB)
        self.sent = []
        self.pair_calls = 0
        self.pair_after = 0
        self.next_message = None
        self.acked = None
        self.role = "initiator"
        self.broadcasts = []
        self.created = None
        self.added = None
        self.next_messages = None
        self.last_limit = None
        self.barriered = None

    def open_rendezvous(self):
        return {"token": "rv-" + "b" * 32, "token_issued": True}

    def await_peer(self, token, timeout=300.0):
        self.pair_calls += 1
        if self.pair_calls <= self.pair_after:
            raise stringcup.PairingTimeout("not yet")
        return {"peer_id": "sc-" + "c" * 24,
                "peer_identity_public_key": self.PUB,
                "role": self.role}

    def join_rendezvous(self, token, timeout=300.0):
        self.role = "responder"
        return self.await_peer(token, timeout)

    def send(self, recipient_id, text):
        self.sent.append((recipient_id, text))
        return 4242

    def receive_one(self, timeout=300.0, ack=True):
        self.acked = ack
        return self.next_message

    def receive_many(self, limit=10, timeout=300.0, ack=True):
        """
        What the MCP `receive` and `receive_all` tools actually call.

        `receive` uses limit=1 so that `has_more` survives; `receive_one`
        discards the page, which is why a queued backlog was invisible to the
        model.
        """
        self.acked = ack
        self.last_limit = limit
        msgs = list(self.next_messages if self.next_messages is not None
                    else ([self.next_message] if self.next_message else []))
        taken = msgs[:limit]
        return stringcup.Page(
            messages=taken,
            count=len(taken),
            has_more=len(msgs) > limit,
            next_since_id=taken[-1].id if taken else None,
        )

    def peer_info(self, peer_id):
        return {
            "external_id": peer_id,
            "identity_public_key": self.PUB,
            "fingerprint": stringcup.fingerprint(self.PUB),
            "fingerprint_short": stringcup.fingerprint_short(self.PUB),
            "key_updated_at": "2026-09-11 00:00:00",
        }

    # -- channels ----------------------------------------------------------
    #
    # These mirror the library signatures the server actually calls. A stub
    # that agreed with the server but not the library is how the
    # `peer_public_key` / `peer_identity_public_key` bug passed every
    # stub-based assertion, so the names here are copied from stringcup.py,
    # not from the handler. `test_mcp_live.py` is the real check.

    def create_topic(self, name, members=None):
        self.created = (name, list(members or []))
        # One deliberately unknown id, so the partial-success path is covered.
        return {"name": name, "unknown": [i for i in (members or []) if i.endswith("zz")]}

    def add_members(self, name, ids):
        self.added = (name, list(ids))
        return {"unknown": []}

    def topics(self):
        return [{"name": "ops", "owner_id": self.id}]

    def topic(self, name, verify_pins=True):
        return {"name": name, "members": [
            {"id": self.id, "identity_public_key": self.PUB,
             "fingerprint_short": stringcup.fingerprint_short(self.PUB)},
            {"id": "sc-" + "c" * 24, "identity_public_key": self.PUB,
             "fingerprint_short": stringcup.fingerprint_short(self.PUB)},
        ]}

    def sync_barrier(self, peer, timeout=120.0):
        self.barriered = peer
        return {"drained": 4, "last_text": "most recent thing\nsecond line",
                "last_line": "most recent thing", "last_seq": 42,
                "synchronised": True}

    def broadcast(self, topic, text, include_self=False):
        self.broadcasts.append((topic, text))
        return {"sent": [{"recipient_id": "sc-" + "c" * 24, "sent_seq": 1}],
                "failed": [], "count": 1, "topic": topic, "recipients": 1}


def with_fake(fake):
    mcp._client = fake


def call(name, arguments=None):
    """Invoke a tool the way the wire layer would, and unwrap the payload."""
    response = mcp.handle({
        "jsonrpc": "2.0", "id": 99, "method": "tools/call",
        "params": {"name": name, "arguments": arguments or {}},
    })
    result = response["result"]
    # The serialized text block and structuredContent must agree.
    assert json.loads(result["content"][0]["text"]) == result["structuredContent"], \
        "text block and structuredContent diverged"
    return result


def test_whoami():
    step("7. whoami")

    fake = FakeClient()
    with_fake(fake)
    payload = call("whoami")["structuredContent"]

    check(payload["id"] == fake.id, "Reports the assigned identifier")
    check(payload["fingerprint"].startswith("sha256:"), "Fingerprint is SSH-style")
    check(payload["relay"] == fake.base_url, "Names the relay it is talking to")
    check("identity_file" in payload,
          "Says where the identity lives, so a human can back it up")


def test_rendezvous_flow():
    step("8. Rendezvous: roles are conferred, not chosen")

    fake = FakeClient()
    with_fake(fake)

    opened = call("open_rendezvous")["structuredContent"]
    check(opened["token"].startswith("rv-"), "Relay-issued token returned")
    check(opened["role"] == "initiator", "Opening makes you the initiator")
    schema = [t for t in mcp.TOOLS if t["name"] == "open_rendezvous"][0]["inputSchema"]
    check(not schema.get("properties"),
          "open_rendezvous takes no arguments — a token cannot be supplied")

    for tool in ("await_peer", "join_rendezvous"):
        props = [t for t in mcp.TOOLS if t["name"] == tool][0]["inputSchema"]["properties"]
        check("role" not in props, "%s exposes no role argument" % tool)

    paired = call("await_peer", {"token": opened["token"]})["structuredContent"]
    check(paired["paired"] is True, "Pairing completes")
    check(paired["peer_id"].startswith("sc-"), "Peer identifier returned")
    check(paired["peer_fingerprint"] == stringcup.fingerprint(FakeClient.PUB),
          "Peer fingerprint recomputed locally from the key")
    check(paired["role"] == "initiator", "Role preserved through await_peer")
    check("out of band" in paired["verify"], "Prompts out-of-band verification")

    joined = call("join_rendezvous", {"token": opened["token"]})["structuredContent"]
    check(joined["role"] == "responder", "Joining makes you the responder")
    check("initiator to speak first" in joined["next"],
          "Responder is told not to speak first")


def test_pairing_not_yet_is_not_an_error():
    step("9. An unpaired rendezvous is a retry, not a failure")

    fake = FakeClient()
    fake.pair_after = 1
    with_fake(fake)

    result = call("await_peer", {"token": "rv-" + "b" * 32})
    payload = result["structuredContent"]
    check(payload["paired"] is False, "Reports not-yet")
    check(result["isError"] is False,
          "Not flagged as an error — the model must not treat it as fatal")
    check("again" in payload["next"], "Tells the model to call again")

    payload = call("await_peer", {"token": "rv-" + "b" * 32})["structuredContent"]
    check(payload["paired"] is True, "The retry pairs")


def test_hold_is_bounded():
    step("10. The hold is clamped")

    check(mcp._hold({}) == mcp.DEFAULT_HOLD, "Default hold applied when absent")
    check(mcp._hold({"hold": 100000}) == mcp.MAX_HOLD, "Absurd hold clamped to the max")
    check(mcp._hold({"hold": 3}) == 3.0, "A short hold is passed through, not floored")
    check(mcp.MAX_HOLD <= 300,
          "Ceiling stays low enough to be reachable before a host kills the call")
    check(stringcup.version_info >= (2, 3, 0),
          "Requires the library release in which a short hold is honoured")
    check(mcp._hold({"hold": 0}) == 1.0, "Zero raised to a floor")
    check(mcp._hold({"hold": "nonsense"}) == mcp.DEFAULT_HOLD,
          "Unparseable hold falls back to the default rather than raising")
    check(mcp.DEFAULT_HOLD < 60,
          "Default hold stays under the 60s tool timeout hosts commonly use")


def test_send_and_receive():
    step("11. send and receive")

    fake = FakeClient()
    with_fake(fake)

    payload = call("send", {"recipient_id": "sc-" + "c" * 24,
                            "text": "hello"})["structuredContent"]
    check(payload["sent_seq"] == 4242, "Returns the sender's own sent_seq")
    check("message_id" not in payload,
          "Does not call it message_id — that name implied a shared id")
    check(fake.sent == [("sc-" + "c" * 24, "hello")], "Passed through to the client")

    fake.next_message = stringcup.Message(
        id=7, sender_id="sc-" + "c" * 24, recipient_id=fake.id,
        text="hi back", created_at="2026-09-11 12:00:00",
    )
    payload = call("receive")["structuredContent"]
    check(payload["received"] is True, "Message returned")
    check(payload["inbox_seq"] == 7, "Inbox sequence returned under its own name")
    check("message_id" not in payload,
          "send and receive do not share a key name for unrelated spaces")
    check(payload["text"] == "hi back", "Plaintext returned")
    check(payload["from"] == "sc-" + "c" * 24, "Sender attributed")
    check(payload["acknowledged"] is True, "Acknowledged by default")
    check(fake.acked is True, "ACK actually requested of the client")

    fake.acked = None
    call("receive", {"ack": False})
    check(fake.acked is False, "ack=false is honoured for peeking")

    fake.next_message = None
    result = call("receive")
    check(result["structuredContent"]["received"] is False, "Empty hold reported")
    check(result["isError"] is False, "An empty inbox is not an error")


def test_missing_arguments():
    step("12. Missing arguments")

    with_fake(FakeClient())
    response = mcp.handle({
        "jsonrpc": "2.0", "id": 1, "method": "tools/call",
        "params": {"name": "send", "arguments": {"text": "no recipient"}},
    })
    check(response["error"]["code"] == -32602, "Missing argument is -32602")
    check("recipient_id" in response["error"]["message"],
          "Error names the argument that was missing")


def test_relay_errors_reach_the_model():
    step("13. A relay refusal reaches the model")

    class Refusing(FakeClient):
        def send(self, recipient_id, text):
            raise stringcup.NotFoundError("recipient not found", status=404)

    with_fake(Refusing())
    result = call("send", {"recipient_id": "sc-" + "z" * 24, "text": "x"})

    check(result["isError"] is True, "Flagged as a tool-execution error")
    check(result["structuredContent"]["status"] == 404, "HTTP status preserved")
    check("not found" in result["structuredContent"]["error"],
          "Message preserved so the model can react")


def test_unexpected_exception_is_contained():
    step("14. An unexpected exception does not kill the server")

    class Exploding(FakeClient):
        def peer_info(self, peer_id):
            raise RuntimeError("boom")

    with_fake(Exploding())
    result = call("peer_info", {"peer_id": "sc-" + "c" * 24})
    check(result["isError"] is True, "Reported as an error result")
    check("boom" in result["structuredContent"]["error"], "Cause surfaced")
    check(mcp.handle({"jsonrpc": "2.0", "id": 2, "method": "ping"})["result"] == {},
          "Server still answers afterwards")


def test_peer_info():
    step("15. peer_info")

    with_fake(FakeClient())
    payload = call("peer_info", {"peer_id": "sc-" + "c" * 24})["structuredContent"]
    check(payload["fingerprint"] == stringcup.fingerprint(FakeClient.PUB),
          "Fingerprint recomputed locally")
    check(payload["key_updated_at"] == "2026-09-11 00:00:00",
          "key_updated_at exposed so rotation is visible")


def test_backlog_is_visible():
    step("16. a queued backlog is visible to the model")

    # The reported failure: receive hands over one message per call, oldest
    # first, and said nothing about what was queued behind it. An agent
    # calling it once per turn answered the oldest unread message while its
    # peer had moved on, so every reply addressed stale content and the peer
    # concluded it was being ignored. Nothing in the result could have told
    # it otherwise.
    fake = FakeClient()
    fake.next_messages = [
        stringcup.Message(id=n, sender_id="sc-" + "c" * 24, recipient_id=fake.id,
                          text="question %d" % n, created_at="2026-09-15 00:00:0%d" % n)
        for n in range(1, 6)
    ]
    with_fake(fake)

    payload = call("receive", {"hold": 1})["structuredContent"]
    check(fake.last_limit == 1, "receive still takes exactly one message")
    check(payload["text"] == "question 1", "It is the OLDEST message, as documented")
    check(payload["more_waiting"] is True,
          "more_waiting flags the four queued behind it")
    check("receive_all" in payload.get("next", ""),
          "And the result names the tool that fixes it")

    payload = call("receive_all", {"hold": 1})["structuredContent"]
    check(payload["count"] == 5, "receive_all returns the whole backlog at once")
    check([m["text"] for m in payload["messages"]]
          == ["question %d" % n for n in range(1, 6)],
          "Oldest first, so the newest message is the last one read")
    check(payload["more_waiting"] is False, "Nothing left behind")

    # A backlog deeper than the limit must stay visible rather than look drained.
    payload = call("receive_all", {"limit": 2, "hold": 1})["structuredContent"]
    check(payload["count"] == 2 and payload["more_waiting"] is True,
          "A backlog deeper than limit still reports more_waiting")
    check("again" in payload.get("next", "") or "raise limit" in payload.get("next", ""),
          "...and says to call again before replying")

    # An empty inbox must not claim a backlog.
    fake.next_messages = []
    payload = call("receive", {"hold": 1})["structuredContent"]
    check(payload["received"] is False, "Empty inbox is still a not-yet")
    check("more_waiting" not in payload or payload["more_waiting"] is False,
          "An empty inbox never claims messages are waiting")


def test_sync_barrier():
    step("17. sync_barrier")

    fake = FakeClient()
    with_fake(fake)
    payload = call("sync_barrier", {"peer_id": "sc-" + "c" * 24})["structuredContent"]
    check(fake.barriered == "sc-" + "c" * 24, "Reaches the library with the peer id")
    check(payload["drained"] == 4, "Reports how much was drained")
    check(payload["peer_last_line"] == "most recent thing",
          "Returns the peer's most recent line, which is the verifiable part")
    check("quoting" in payload["next"] or "quot" in payload["next"],
          "Tells the model to send the quoted line back, not to argue")


def test_channels():
    step("18. channels")

    fake = FakeClient()
    with_fake(fake)

    bogus = "sc-" + "y" * 22 + "zz"
    payload = call("create_channel",
                   {"name": "ops", "members": ["sc-" + "c" * 24, bogus]})["structuredContent"]
    check(fake.created == ("ops", ["sc-" + "c" * 24, bogus]),
          "create_channel passes the member list straight through")
    check(payload["unknown"] == [bogus], "An unrecognised id is reported, not raised")
    check(payload["members_added"] == 1,
          "members_added counts only what was actually added, not what was asked")
    check(payload["owner"] == fake.id, "The creator is named as owner")

    payload = call("channel_info", {"name": "ops"})["structuredContent"]
    check(payload["count"] == 2, "Roster reports every member")
    check([m for m in payload["members"] if m["me"]][0]["id"] == fake.id,
          "The caller's own entry is flagged, so an agent can tell itself apart")
    check(all(m.get("fingerprint_short") for m in payload["members"]),
          "Every member carries the short fingerprint a human reads aloud")
    check(not any("identity_public_key" in m for m in payload["members"]),
          "Raw public keys stay out of the model's context; fingerprints are enough")

    payload = call("list_channels")["structuredContent"]
    check(payload["count"] == 1 and payload["channels"][0]["mine"] is True,
          "list_channels marks a channel this identity owns")

    payload = call("broadcast", {"name": "ops", "text": "drained"})["structuredContent"]
    check(fake.broadcasts == [("ops", "drained")], "broadcast reaches the library")
    check(payload["delivered"] == 1 and payload["recipients"] == 1,
          "delivered and recipients are reported separately")
    check(payload["failed"] == [], "An empty failure list is present rather than omitted")

    payload = call("add_to_channel",
                   {"name": "ops", "members": ["sc-" + "d" * 24]})["structuredContent"]
    check(fake.added == ("ops", ["sc-" + "d" * 24]), "add_to_channel reaches the library")
    check(payload["added"] == 1, "added counts the new members")

    # Since 3.4.0 a broadcast IS labelled, inside the ciphertext. Both states
    # are asserted, because `channel: null` is ambiguous by construction and
    # the tool description says so: it means "direct message OR a sender too
    # old to label", never "certainly a direct message".
    fake.next_messages = [stringcup.Message(
        id=1, sender_id="sc-" + "c" * 24, recipient_id=fake.id,
        text="hi", created_at="2026-09-14 00:00:00")]
    payload = call("receive", {"hold": 1})["structuredContent"]
    check(payload["channel"] is None,
          "An unlabelled message reports channel None, not a guess")

    fake.next_messages = [stringcup.Message(
        id=2, sender_id="sc-" + "c" * 24, recipient_id=fake.id,
        text="hi all", created_at="2026-09-14 00:00:00", channel="ops")]
    payload = call("receive", {"hold": 1})["structuredContent"]
    check(payload["channel"] == "ops",
          "A labelled broadcast names its channel on receive")
    payload = call("receive_all", {"hold": 1})["structuredContent"]
    check(payload["messages"][0]["channel"] == "ops",
          "...and on receive_all")

    # The label must never reach the relay: it lives inside the ciphertext.
    source = open(os.path.join(HERE, "stringcup.py")).read()
    check("CHANNEL_LABEL_RE" in source and '"channel"' not in
          source.split("def encrypt")[1].split("def decrypt")[0],
          "encrypt() puts no channel field in the header the relay can read")


def test_no_remote_transport():
    step("19. There is no remote transport")

    source = open(os.path.join(HERE, "stringcup_mcp.py")).read()
    check("http.server" not in source and "HTTPServer" not in source,
          "No HTTP server — this process holds a private key and must stay local")
    check("RUN IT LOCALLY" in source, "The local-only constraint is stated up front")


def main():
    print("=" * 48)
    print("  Stringcup MCP server tests")
    print("=" * 48)

    test_handshake()
    test_version_negotiation()
    test_tools_list()
    test_protocol_errors()
    test_batch()
    test_stdout_is_clean()
    test_whoami()
    test_rendezvous_flow()
    test_pairing_not_yet_is_not_an_error()
    test_hold_is_bounded()
    test_send_and_receive()
    test_missing_arguments()
    test_relay_errors_reach_the_model()
    test_unexpected_exception_is_contained()
    test_peer_info()
    test_backlog_is_visible()
    test_sync_barrier()
    test_channels()
    test_no_remote_transport()

    print("\n" + "=" * 48)
    if FAIL:
        print("  \033[31m%d FAILED\033[0m, %d passed" % (FAIL, PASS))
    else:
        print("  \033[32mALL TESTS PASSED (%d assertions)\033[0m" % PASS)
    print("=" * 48)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
