#!/usr/bin/env python3
"""
End-to-end test of the Stringcup MCP server against a live relay.

Spawns two independent server processes with separate identity files and has
them find each other and hold a conversation using nothing but MCP tool calls
— the same path an MCP host would drive. This is what proves the wrapper is
usable, as opposed to merely well-shaped.

    python3 test_mcp_live.py [base_url]

Registers two identities against the 5/hour per-IP cap. Run it on the server
and clear writable/cache/ratelimit/ if you need to repeat it.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
BASE_URL = sys.argv[1] if len(sys.argv) > 1 else "https://stringcup.com/api/v2"

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


class Peer:
    """One MCP server process, driven the way a host drives it."""

    def __init__(self, name, workdir):
        self.name = name
        env = dict(os.environ)
        env["STRINGCUP_IDENTITY"] = os.path.join(workdir, "%s.json" % name)
        env["STRINGCUP_BASE_URL"] = BASE_URL
        env["STRINGCUP_TRANSCRIPT"] = os.path.join(workdir, "%s.jsonl" % name)
        self.transcript = env["STRINGCUP_TRANSCRIPT"]

        self.proc = subprocess.Popen(
            [sys.executable, os.path.join(HERE, "stringcup_mcp.py")],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env=env, universal_newlines=True, bufsize=1,
        )
        self._id = 0
        self.initialize()

    def _send(self, method, params=None):
        self._id += 1
        message = {"jsonrpc": "2.0", "id": self._id, "method": method}
        if params is not None:
            message["params"] = params
        self.proc.stdin.write(json.dumps(message) + "\n")
        self.proc.stdin.flush()
        line = self.proc.stdout.readline()
        if not line:
            raise RuntimeError("%s: server closed stdout" % self.name)
        return json.loads(line)

    def initialize(self):
        self._send("initialize", {"protocolVersion": "2025-06-18",
                                  "capabilities": {},
                                  "clientInfo": {"name": "live-test", "version": "0"}})
        self.proc.stdin.write(
            json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n")
        self.proc.stdin.flush()

    def call(self, tool, arguments=None):
        response = self._send("tools/call",
                              {"name": tool, "arguments": arguments or {}})
        if "error" in response:
            raise RuntimeError("%s: %s" % (self.name, response["error"]))
        result = response["result"]
        if result.get("isError"):
            raise RuntimeError("%s: tool error: %s" % (self.name, result["structuredContent"]))
        return result["structuredContent"]

    def close(self):
        try:
            self.proc.stdin.close()
            self.proc.wait(timeout=10)
        except Exception:
            self.proc.kill()


def main():
    print("=" * 48)
    print("  Stringcup MCP live conversation")
    print("  relay: %s" % BASE_URL)
    print("=" * 48)

    workdir = tempfile.mkdtemp(prefix="stringcup-mcp-")
    alice = bob = None

    try:
        step("1. Two independent agents come up")
        alice = Peer("alice", workdir)
        bob = Peer("bob", workdir)

        a = alice.call("whoami")
        b = bob.call("whoami")
        check(a["id"].startswith("sc-"), "Alice registered: %s" % a["id"])
        check(b["id"].startswith("sc-"), "Bob registered:   %s" % b["id"])
        check(a["id"] != b["id"], "They are distinct identities")
        check(os.path.exists(a["identity_file"]), "Alice's identity persisted to disk")

        step("2. Identity survives a restart")
        alice.close()
        alice = Peer("alice", workdir)
        check(alice.call("whoami")["id"] == a["id"],
              "Same identifier after restart — no re-registration")

        step("3. They find each other with no prior knowledge")
        opened = alice.call("open_rendezvous")
        check(opened["token"].startswith("rv-"), "Relay issued a token")
        check(opened["role"] == "initiator", "Alice is the initiator by opening")

        # The operator relays the token — the one step MCP does not remove.
        joined = bob.call("join_rendezvous", {"token": opened["token"], "hold": 30})
        check(joined["paired"] is True, "Bob joined and paired")
        check(joined["role"] == "responder", "Bob is the responder by joining")
        check(joined["peer_id"] == a["id"], "Bob learned Alice's identifier")

        paired = alice.call("await_peer", {"token": opened["token"], "hold": 30})
        check(paired["paired"] is True, "Alice saw Bob arrive")
        check(paired["peer_id"] == b["id"], "Alice learned Bob's identifier")
        check(paired["role"] == "initiator",
              "Alice is still the initiator after re-polling, not flipped to responder")

        step("4. Fingerprints agree across the pair")
        check(paired["peer_fingerprint"] == b["fingerprint"],
              "What Alice was told about Bob matches what Bob computed locally")
        check(joined["peer_fingerprint"] == a["fingerprint"],
              "And the same in the other direction")

        step("5. A conversation, three turns each way")
        transcript = []
        for turn in range(3):
            sent = alice.call("send", {"recipient_id": b["id"],
                                       "text": "ping %d" % turn})
            check(sent["sent"] is True, "Alice sent turn %d (id %s)"
                  % (turn, sent["message_id"]))

            got = bob.call("receive", {"hold": 30})
            check(got["received"] is True and got["text"] == "ping %d" % turn,
                  "Bob received %r" % got.get("text"))
            check(got["acknowledged"] is True, "Bob's receive acknowledged it")
            check(got["from"] == a["id"], "Attributed to Alice")
            transcript.append(got["text"])

            bob.call("send", {"recipient_id": a["id"], "text": "pong %d" % turn})
            back = alice.call("receive", {"hold": 30})
            check(back["received"] is True and back["text"] == "pong %d" % turn,
                  "Alice received %r" % back.get("text"))

        check(transcript == ["ping 0", "ping 1", "ping 2"], "Order preserved")

        step("5b. A short hold really is short")
        # Regression: receive_one/await_peer used to pass a fixed 25s
        # server-side wait and only check the deadline afterwards, so any hold
        # under 25 still parked a full cycle. An agent measured hold:3 taking
        # 25.3s — which defeats the purpose, since a short hold exists to stay
        # under the host's tool-call timeout.
        import time as _time
        start = _time.monotonic()
        idle = bob.call("receive", {"hold": 3})
        elapsed = _time.monotonic() - start
        check(idle["received"] is False, "Short hold returned not-yet")
        check(elapsed < 8,
              "hold=3 returned in %.1fs, not a full 25s cycle" % elapsed)

        step("6. The ACK really deleted the messages")
        idle = bob.call("receive", {"hold": 2})
        check(idle["received"] is False,
              "Bob's inbox is empty — nothing redelivered, which is the failure "
              "the callback API used to cause")

        step("7. Transcripts were written")
        for peer, expected in ((alice, "pong 0"), (bob, "ping 0")):
            check(os.path.exists(peer.transcript),
                  "%s wrote a transcript" % peer.name)
            body = open(peer.transcript).read()
            check(expected in body,
                  "%s's transcript records the conversation" % peer.name)

        step("8. peer_info corroborates the key out of band")
        looked_up = alice.call("peer_info", {"peer_id": b["id"]})
        check(looked_up["fingerprint"] == b["fingerprint"],
              "Independent lookup matches Bob's own fingerprint")

    finally:
        for peer in (alice, bob):
            if peer is not None:
                peer.close()
        shutil.rmtree(workdir, ignore_errors=True)

    print("\n" + "=" * 48)
    if FAIL:
        print("  \033[31m%d FAILED\033[0m, %d passed" % (FAIL, PASS))
    else:
        print("  \033[32mALL TESTS PASSED (%d assertions)\033[0m" % PASS)
    print("=" * 48)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
