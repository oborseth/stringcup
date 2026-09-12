"""
End-to-end test for the Stringcup Python client, against a live server.

Usage:  python3 test_stringcup.py [base_url]

Registration is capped at 5/hour per IP and this registers two identities.
When run on the server host, clear writable/cache/ratelimit/ to re-run.
"""

import json
import os
import shutil
import sys
import tempfile
import time
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from stringcup import (  # noqa: E402
    AuthError,
    Client,
    DecryptionError,
    Identity,
    NotFoundError,
    ValidationError,
    decrypt,
    encrypt,
)

BASE = sys.argv[1] if len(sys.argv) > 1 else "https://stringcup.com/api/v2"

PASSED = 0


def step(msg):
    print(f"\n[STEP] {msg}")


def ok(msg):
    global PASSED
    PASSED += 1
    print(f"  ✓  {msg}")


def fail(msg):
    print(f"  ✗  {msg}")
    sys.exit(1)


def check(cond, msg):
    ok(msg) if cond else fail(msg)


def same(expected, actual, msg):
    if expected == actual:
        ok(msg)
    else:
        fail(f"{msg} — expected {expected!r}, got {actual!r}")


workdir = tempfile.mkdtemp(prefix="stringcup-test-")
suffix = uuid.uuid4().hex[:8]

print("Stringcup Python client — end-to-end test")
print(f"Base    : {BASE}")
print(f"Workdir : {workdir}")

try:
    # ----------------------------------------------------------------
    step("1. Register two agents, persisting identities")
    alice_path = os.path.join(workdir, "alice.json")
    bob_path = os.path.join(workdir, "bob.json")

    alice = Client.load_or_register(alice_path, base_url=BASE)
    bob = Client.load_or_register(bob_path, base_url=BASE)
    alice_id, bob_id = alice.id, bob.id
    ok(f"Registered {alice.id} and {bob.id}")

    check(os.path.exists(alice_path), "Identity file written")
    same(0o600, os.stat(alice_path).st_mode & 0o777, "Identity file is 0600")

    # ----------------------------------------------------------------
    step("2. Identity is reused, not re-registered")
    token_before = alice.identity.api_token
    alice_again = Client.load_or_register(alice_path, base_url=BASE)
    same(token_before, alice_again.identity.api_token, "Same token loaded from disk")
    same(alice_id, alice_again.id, "Same assigned id loaded from disk")

    try:
        alice._request("POST", "/identities",
                       {"external_id": "chosen-" + suffix,
                        "identity_public_key": alice.identity.public_key_b64},
                       authenticated=False)
        fail("Choosing an external_id should be rejected")
    except ValidationError:
        ok("Server rejects a client-chosen external_id")

    # ----------------------------------------------------------------
    step("3. Peer key lookup and caching")
    key = alice.peer_public_key(bob_id)
    same(bob.identity.public_key_b64, key, "Fetched key matches Bob's actual key")
    check(bob_id in alice._peer_keys, "Key cached after first fetch")

    try:
        alice.peer_public_key(f"nope-{suffix}")
        fail("Unknown peer should raise NotFoundError")
    except NotFoundError:
        ok("Unknown peer raises NotFoundError")

    # ----------------------------------------------------------------
    step("4. Round trip: Alice -> Bob")
    text = "Hello Bob — sent from the Python client."
    mid = alice.send(bob_id, text)
    check(isinstance(mid, int) and mid > 0, f"Sent, sent_seq={mid}")

    received = bob.receive()
    same(1, len(received), "Bob received exactly one message")
    same(text, received[0].text, "Plaintext survived the round trip")
    same(alice_id, received[0].sender_id, "sender_id is correct")
    same(mid, received[0].id, "message id matches the send")

    result = bob.ack_all()
    same(1, result["count"], "ack_all cleared the message")
    same(0, len(bob.receive()), "Inbox empty after ACK")

    # ----------------------------------------------------------------
    step("5. Reply: Bob -> Alice")
    reply = "Got it, Alice. Replying with unicode: éà中文 \U0001f510"
    bob.send(alice_id, reply)
    got = alice.receive()
    same(1, len(got), "Alice received the reply")
    same(reply, got[0].text, "Unicode plaintext round-trips intact")
    alice.ack_all()

    # ----------------------------------------------------------------
    step("6. Idempotent send")
    key_id = f"test-idem-{suffix}"
    first = alice.send(bob_id, "only once", idempotency_key=key_id)
    replay = alice.send(bob_id, "only once", idempotency_key=key_id)
    same(first, replay, "Replay returns the original sent_seq")

    page = bob.fetch()
    same(1, page.count, "Only one message actually stored")
    bob.ack(page.ids)

    # ----------------------------------------------------------------
    step("7. Pagination")
    total = 12
    for i in range(1, total + 1):
        alice.send(bob_id, f"paginated #{i}")
    ok(f"Alice sent {total} messages")

    page = bob.fetch(limit=5)
    same(5, page.count, "Page honours limit=5")
    same(True, page.has_more, "has_more true with a backlog")
    check(page.next_since_id is not None, f"Cursor supplied: {page.next_since_id}")

    collected, since, pages = [], None, 0
    while True:
        p = bob.fetch(limit=5, since_id=since)
        pages += 1
        collected += p.messages
        if not p.has_more:
            break
        since = p.next_since_id
        if pages > 10:
            fail("cursor did not terminate")

    same(3, pages, "Walked the backlog in 3 pages")
    same(total, len(collected), f"Cursor returned all {total} messages")
    ids = [m.id for m in collected]
    same(len(ids), len(set(ids)), "No duplicates across pages")
    same(sorted(ids), ids, "Ascending id order")
    same(
        [f"paginated #{i}" for i in range(1, total + 1)],
        [m.text for m in collected],
        "All plaintexts decrypt in order",
    )

    # ----------------------------------------------------------------
    step("8. drain() processes the whole backlog and ACKs it")
    seen = []
    processed = bob.drain(seen.append, limit=5)
    same(total, processed, f"drain() handled all {total} messages")
    same(total, len(seen), "Handler saw every message")
    same(0, bob.fetch().count, "Inbox empty after drain")

    # ----------------------------------------------------------------
    step("8b. Transcript records name each numbering space separately")
    # The two sequences are unrelated spaces; one shared "message_id" key
    # implied they were comparable, and the README documented a key the code
    # never wrote. An agent parsing per the docs got a KeyError.
    tpath = os.path.join(workdir, "transcript.jsonl")
    t = Client(alice.identity, base_url=BASE, transcript=tpath)
    t.send(bob_id, "for the transcript")
    got = bob.receive_one(timeout=30)
    check(got is not None, "Message arrived for the transcript check")
    tb = Client(bob.identity, base_url=BASE, transcript=tpath)
    tb._log_transcript("in", alice_id, got.id, got.text)

    rows = [json.loads(line) for line in open(tpath, encoding="utf-8")]
    out_rows = [r for r in rows if r["direction"] == "out"]
    in_rows = [r for r in rows if r["direction"] == "in"]
    check(out_rows and "sent_seq" in out_rows[0], "Outbound record carries sent_seq")
    check(out_rows and "message_id" not in out_rows[0],
          "Outbound record does not use the abolished shared name")
    check(in_rows and "inbox_seq" in in_rows[0], "Inbound record carries inbox_seq")

    step("9. Batch ACK semantics")
    # ACK handles come from the inbox, never from send(). send() returns the
    # *sender's* own sequence, which names nothing in the recipient's inbox.
    # This test used to ACK the values send() returned and passed only because
    # the two counters happened to line up at 1,2,3.
    for i in range(3):
        alice.send(bob_id, f"batch {i}")
    ids = [m.id for m in bob.fetch(limit=10).messages]
    same(3, len(ids), "Three messages waiting in Bob's inbox")
    res = bob.ack(ids)
    same(3, res["count"], "Batch ACK cleared all three")

    res = bob.ack(ids)
    same(0, res["count"], "Re-ACK acknowledges nothing")
    same(sorted(ids), sorted(res["not_found"]), "Already-gone ids reported not_found")

    # A sequence number names a message inside one inbox only, so Bob cannot
    # express Alice's message at all. There is no 'forbidden' outcome any more:
    # reporting one would confirm that another identity's message exists.
    bob.send(alice_id, "for alice only")
    alice_seqs = [m.id for m in alice.fetch(limit=10).messages]
    check(alice_seqs != [], "Alice's inbox has the message")

    res = bob.ack([alice_seqs[0]])
    same([], res["forbidden"], "forbidden is always empty — no cross-inbox addressing")
    still_there = [m.id for m in alice.fetch(limit=10).messages]
    check(alice_seqs[0] in still_there, "Alice's message survived Bob's ACK attempt")
    alice.drain(lambda m: None)

    same(0, bob.ack([])["count"], "Empty ACK is a no-op, not an error")

    # ----------------------------------------------------------------
    step("10. Rate limit budget is tracked")
    bob.fetch()
    check(bob.rate_limit["limit"] == 300, f"Inbox limit seen: {bob.rate_limit['limit']}")
    check(
        isinstance(bob.rate_limit["remaining"], int),
        f"Remaining tracked: {bob.rate_limit['remaining']}",
    )
    check(bob.rate_limit["reset"] > int(time.time()), "Reset is in the future")

    # ----------------------------------------------------------------
    step("11. Token introspection")
    info = alice.token_info()
    same(alice_id, info["identity_id"], "Token belongs to Alice")
    same(30, info["inactivity_ttl_days"], "TTL is 30 days")
    check(info["expires_in_seconds"] > 29 * 86400, "Expiry ~30 days out")

    # ----------------------------------------------------------------
    step("12. Decryption failures are contained")
    good = encrypt(alice_id, bob_id, bob.identity.public_key_b64, "fine")
    tampered = {
        "id": 1,
        "sender_id": alice_id,
        "recipient_id": bob_id,
        "header": good["header"],
        "ciphertext": good["ciphertext"],
    }
    same("fine", decrypt(bob.identity.private_key, bob_id, tampered), "Baseline decrypts")

    wrong_ctx = dict(tampered, sender_id="not-the-sender")
    try:
        decrypt(bob.identity.private_key, bob_id, wrong_ctx)
        fail("Wrong HKDF context should fail")
    except DecryptionError as exc:
        check("info mismatch" in str(exc), "Wrong HKDF info raises a diagnostic error")

    bad_algo = dict(tampered, header=dict(good["header"], algo="rot13"))
    try:
        decrypt(bob.identity.private_key, bob_id, bad_algo)
        fail("Unknown algo should fail")
    except DecryptionError:
        ok("Unknown algo rejected")

    # An undecryptable message must not poison the whole page.
    alice.send(bob_id, "readable")
    page = bob.fetch()
    same(1, len(page.messages), "Readable message still returned")
    bob.ack(page.ids)

    # ----------------------------------------------------------------
    step("13. Errors are typed")
    broken = Client(Identity(alice_id, alice.identity.private_key_b64, "bogus"), base_url=BASE)
    try:
        broken.fetch()
        fail("Bad token should raise AuthError")
    except AuthError:
        ok("Bad token raises AuthError")

    try:
        alice.send(f"ghost-{suffix}", "nobody home")
        fail("Unknown recipient should raise")
    except NotFoundError:
        ok("Unknown recipient raises NotFoundError")

    try:
        alice.fetch(limit=99999)
        ok("Oversized limit clamped client-side rather than rejected")
    except ValidationError:
        fail("limit should be clamped before it reaches the server")

    # ----------------------------------------------------------------
    step("14. Token rotation")
    old_token = bob.identity.api_token
    alice.send(bob_id, "survives rotation")

    new_token = bob.rotate_token(save_to=bob_path)
    check(new_token != old_token, "Rotation produced a new token")
    same(new_token, Identity.load(bob_path).api_token, "New token persisted to disk")

    msgs = bob.receive()
    same(1, len(msgs), "Same inbox after rotation")
    same("survives rotation", msgs[0].text, "Message still decrypts post-rotation")
    bob.ack_all()

    stale = Client(Identity(bob_id, bob.identity.private_key_b64, old_token), base_url=BASE)
    try:
        stale.fetch()
        fail("Old token should be revoked")
    except AuthError:
        ok("Old token is revoked")

    # ----------------------------------------------------------------
    step("15. Cleanup")
    alice.drain(lambda m: None)
    bob.drain(lambda m: None)
    ok("Both inboxes drained")

    print("\n" + "=" * 48)
    print(f"  ALL TESTS PASSED ({PASSED} assertions)")
    print("=" * 48 + "\n")

finally:
    shutil.rmtree(workdir, ignore_errors=True)
