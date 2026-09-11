"""
Tests for long polling, key pinning, and topics / fan-out.

Usage:  python3 test_features_v11.py [base_url]
"""

import os
import re
import secrets
import shutil
import sys
import tempfile
import threading
import time
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from stringcup import (  # noqa: E402
    AuthError,
    Client,
    KeyPinMismatch,
    NotFoundError,
    StringcupError,
    TrustStore,
    ValidationError,
    fingerprint,
    fingerprint_short,
)

BASE = sys.argv[1] if len(sys.argv) > 1 else "https://stringcup.com/api/v2"
PASSED = 0


def step(m):
    print(f"\n[STEP] {m}")


def ok(m):
    global PASSED
    PASSED += 1
    print(f"  ✓  {m}")


def fail(m):
    print(f"  ✗  {m}")
    sys.exit(1)


def check(c, m):
    ok(m) if c else fail(m)


def same(e, a, m):
    ok(m) if e == a else fail(f"{m} — expected {e!r}, got {a!r}")


work = tempfile.mkdtemp(prefix="scv11-")
sfx = uuid.uuid4().hex[:8]


print("Stringcup v1.1 feature test")
print(f"Base: {BASE}")

try:
    step("1. Register three agents")
    a = Client.register(base_url=BASE)
    b = Client.register(base_url=BASE)
    c = Client.register(base_url=BASE)
    aid, bid, cid = a.id, b.id, c.id
    ok(f"{aid} / {bid} / {cid}")
    check(all(x.startswith("sc-") for x in (aid, bid, cid)), "Server assigned all three ids")

    # ================================================================
    step("2. Long polling")
    # ================================================================
    t0 = time.perf_counter()
    page = b.fetch(wait=4)
    held = time.perf_counter() - t0
    same(0, page.count, "Empty inbox returns empty")
    same("waited", page.long_poll, "Server reports it parked the request")
    check(3.5 < held < 6.5, f"Held ~4s as asked ({held:.2f}s)")

    # A message arriving mid-hold should return almost immediately.
    result = {}

    def waiter():
        t = time.perf_counter()
        result["page"] = b.fetch(wait=20)
        result["elapsed"] = time.perf_counter() - t

    th = threading.Thread(target=waiter)
    th.start()
    time.sleep(1.5)
    a.send(bid, "delivered by long poll")
    th.join()

    same(1, result["page"].count, "Message delivered during the hold")
    latency = result["elapsed"] - 1.5
    check(latency < 2.0, f"Returned ~{latency*1000:.0f}ms after the send (vs ~7700ms polling)")
    same("delivered by long poll", result["page"].messages[0].text, "Plaintext intact")
    b.drain(lambda m: None)

    t0 = time.perf_counter()
    page = b.fetch(wait=0)
    check(time.perf_counter() - t0 < 2.0, "wait=0 returns at once (backward compatible)")
    same("off", page.long_poll, "wait=0 reports long polling off")

    try:
        b.fetch(wait=999)
        ok("Oversized wait clamped client-side, not rejected")
    except ValidationError:
        fail("wait should be clamped before it reaches the server")

    step("2b. listen() delivers via long poll without burning budget")
    seen = []
    before = b.rate_limit["remaining"]

    def listener():
        b.listen(seen.append, wait=10, idle_timeout=14, stop=lambda: len(seen) >= 2)

    th = threading.Thread(target=listener)
    th.start()
    time.sleep(1.0)
    t_send = time.perf_counter()
    a.send(bid, "first")
    a.send(bid, "second")
    th.join(timeout=30)

    same(["first", "second"], [m.text for m in seen], "listen() received both messages")
    check(time.perf_counter() - t_send < 8.0, "Both arrived within seconds, not poll intervals")
    after = b.rate_limit["remaining"]
    check(before - after < 12, f"Cheap in requests: {before - after} used for the whole exchange")
    b.drain(lambda m: None)

    # ================================================================
    step("3. Key fingerprints")
    # ================================================================
    info = a.peer_info(bid)
    same(fingerprint(b.identity.public_key_b64), info["fingerprint"],
         "Locally computed fingerprint matches the peer's real key")
    same(b.my_fingerprint, info["fingerprint"], "Peer's self-reported fingerprint agrees")
    check(info["fingerprint"].startswith("sha256:"), f"SSH-style form: {info['fingerprint']}")
    same(fingerprint_short(b.identity.public_key_b64), info["fingerprint_short"],
         f"Short form for humans: {info['fingerprint_short']}")
    check(info.get("key_updated_at") is not None, "key_updated_at exposed for rotation detection")

    step("3b. Explicit pin blocks a substituted key")
    a.peer_public_key(bid, refresh=True, pin=b.my_fingerprint)
    ok("Correct pin accepted")

    try:
        a.peer_public_key(bid, refresh=True, pin=c.my_fingerprint)
        fail("Wrong pin must be rejected")
    except KeyPinMismatch as exc:
        same(bid, exc.peer_id, "KeyPinMismatch names the peer")
        ok("Wrong pin rejected before any encryption happens")

    step("3c. Trust store: TOFU then detect change")
    store_path = os.path.join(work, "known_peers.json")
    store = TrustStore(store_path)
    pinner = Client(a.identity, base_url=BASE, trust_store=store)

    pinner.peer_public_key(bid)
    same(b.my_fingerprint, store.get(bid), "First sight pinned the fingerprint")
    same(0o600, os.stat(store_path).st_mode & 0o777, "Trust store is 0600")

    pinner.peer_public_key(bid, refresh=True)
    ok("Unchanged key passes on later lookups")

    # Simulate a substituted key by repinning to a different one.
    store.pin(bid, c.my_fingerprint)
    try:
        pinner.peer_public_key(bid, refresh=True)
        fail("Changed key must raise")
    except KeyPinMismatch:
        ok("A key that no longer matches the pin raises rather than re-keying")

    store.repin(bid, b.my_fingerprint)
    pinner.peer_public_key(bid, refresh=True)
    ok("repin() clears the mismatch after out-of-band verification")

    same(b.my_fingerprint, TrustStore(store_path).get(bid), "Pins persist across reload")

    step("3d. Real key rotation is detected")
    rot = Client.register(base_url=BASE)
    rot_id = rot.id
    watcher = Client(a.identity, base_url=BASE, trust_store=TrustStore(os.path.join(work, "w2.json")))
    watcher.peer_public_key(rot_id)
    first_fp = rot.my_fingerprint

    # Rotate the key on the same identity, via PUT.
    from stringcup import Identity  # noqa: E402
    fresh = Identity.generate()
    rot.update_identity(public_key_b64=fresh.public_key_b64)
    check(fresh.public_key_b64 != rot.identity.public_key_b64, "Identity re-keyed server-side")

    try:
        watcher.peer_public_key(rot_id, refresh=True)
        fail("Rotation should trip the pin")
    except KeyPinMismatch as exc:
        same(first_fp, exc.expected, "Mismatch reports the previously pinned fingerprint")
        ok("Genuine rotation is caught by the trust store")

    # ================================================================
    step("4. Topics")
    # ================================================================
    topic = f"v11-topic-{sfx}"
    created = a.create_topic(topic, members=[bid, cid])
    same(topic, created["name"], "Topic created")
    same(aid, created["owner"], "Creator owns it")
    same(3, created["member_count"], "Owner is a member alongside the two seeds")
    same([], created["unknown"], "No unknown seeds")

    step("4b. Roster carries keys and fingerprints")
    roster = a.topic(topic)
    ids = sorted(m["id"] for m in roster["members"])
    same(sorted([aid, bid, cid]), ids, "All three members listed")
    same(True, roster["is_owner"], "Owner flag set for the creator")

    by_id = {m["id"]: m for m in roster["members"]}
    same(b.my_fingerprint, by_id[bid]["fingerprint"], "Roster fingerprints are correct")
    check(all("identity_public_key" in m for m in roster["members"]),
          "Every member carries a public key — one call is enough to encrypt for all")

    step("4c. Membership is private to members")
    outsider = Client.register(base_url=BASE)
    try:
        outsider.topic(topic)
        fail("Non-member must not read the roster")
    except NotFoundError:
        ok("Non-member gets 404, so topics cannot be enumerated")

    step("4d. Duplicate and unknown handling")
    try:
        a.create_topic(topic)
        fail("Duplicate topic name should conflict")
    except StringcupError as exc:
        same(409, exc.status, "Duplicate name returns 409")

    res = a.add_members(topic, [bid, f"ghost-{sfx}"])
    same([], res["added"], "Existing member is not re-added")
    same([f"ghost-{sfx}"], res["unknown"], "Unknown id reported, not fatal")
    same(3, res["member_count"], "Membership unchanged")

    step("4e. Only the owner may change membership")
    try:
        b.add_members(topic, [outsider.id])
        fail("Non-owner must not add members")
    except StringcupError as exc:
        same(403, exc.status, "Non-owner add is 403")

    res = b.remove_member(topic, bid)
    same(bid, res["removed"], "A member may remove itself")
    same(2, res["member_count"], "Roster shrank")

    a.add_members(topic, [bid])
    ok("Owner re-added the member")

    try:
        a.remove_member(topic, aid)
        fail("Owner removal should be refused")
    except StringcupError as exc:
        same(409, exc.status, "Owner cannot be removed (delete the topic instead)")

    step("4f. topics() lists memberships")
    names = [t["name"] for t in b.topics()]
    check(topic in names, "Member sees the topic in its list")
    same(False, [t for t in b.topics() if t["name"] == topic][0]["is_owner"],
         "Member is not marked owner")

    # ================================================================
    step("5. Fan-out broadcast")
    # ================================================================
    for cl in (b, c):
        cl.drain(lambda m: None)

    result = a.broadcast(topic, "status report please")
    same(2, result["count"], "Delivered to both other members in one request")
    same([], result["failed"], "No failures")
    same(2, result["recipients"], "Sender excluded from its own broadcast")

    got_b = b.receive()
    got_c = c.receive()
    same(1, len(got_b), "B received the broadcast")
    same(1, len(got_c), "C received the broadcast")
    same("status report please", got_b[0].text, "B decrypted it")
    same("status report please", got_c[0].text, "C decrypted it")

    step("5b. Each recipient gets a distinct ciphertext (E2EE preserved)")
    same(aid, got_b[0].sender_id, "Sender attributed correctly")
    check(
        got_b[0].header["ephemeral_pub"] != got_c[0].header["ephemeral_pub"],
        "Different ephemeral keys per recipient — not one shared ciphertext",
    )
    b.ack_all()
    c.ack_all()

    step("5c. include_self")
    a.drain(lambda m: None)
    result = a.broadcast(topic, "echo", include_self=True)
    same(3, result["count"], "include_self reaches the sender too")
    same(1, len(a.receive()), "Sender got its own copy")
    a.ack_all()
    b.drain(lambda m: None)
    c.drain(lambda m: None)

    step("5d. Partial failure is reported, not raised")
    res = a.send_many([bid, f"ghost-{sfx}", cid], "partial")
    same(2, res["count"], "Reachable recipients still received it")
    same(1, len(res["failed"]), "The unknown recipient is reported in failed")
    check(
        any("ghost" in str(f.get("recipient_id")) for f in res["failed"]),
        "Failure names the unreachable recipient",
    )
    b.drain(lambda m: None)
    c.drain(lambda m: None)

    step("5e. send_many enforces the batch ceiling")
    try:
        a.send_many([f"x{i}" for i in range(201)], "too many")
        fail("Should refuse more than 200 recipients")
    except ValidationError:
        ok("Refuses more than 200 recipients client-side")

    step("6. Topic deletion")
    try:
        b.delete_topic(topic)
        fail("Non-owner must not delete")
    except StringcupError as exc:
        same(403, exc.status, "Non-owner delete is 403")

    same("deleted", a.delete_topic(topic)["status"], "Owner deleted the topic")
    try:
        a.topic(topic)
        fail("Deleted topic should be gone")
    except NotFoundError:
        ok("Roster no longer resolves")

    step("6b. Rendezvous")
    first = a.rendezvous("initiator", wait=0)
    same("waiting", first["status"], "First arrival waits")
    same(None, first["peer_id"], "No peer id before the counterpart arrives")
    same(True, first.get("token_issued"), "Server issued the token")

    rv = first["token"]
    check(re.fullmatch(r"rv-[a-z2-7]{32}", rv) is not None, f"Minted token is 160 bits: {rv}")

    second = b.rendezvous("responder", rv, wait=0)
    same("paired", second["status"], "Second arrival completes the pairing")
    same(a.id, second["peer_id"], "Responder learned the initiator's assigned id")
    same(a.my_fingerprint, second["peer_fingerprint"], "Peer fingerprint recomputed locally")

    back = a.rendezvous("initiator", rv, wait=0)
    same(b.id, back["peer_id"], "Initiator learned the responder's assigned id")

    for bad in ["project-alpha", "hunter2hunter2hunter2"]:
        try:
            c.rendezvous("responder", bad, wait=0)
            fail(f"Self-chosen token {bad!r} should be rejected")
        except ValidationError:
            pass
    ok("Self-invented tokens rejected — minting is mandatory")

    fake = "rv-" + "".join(secrets.choice("abcdefghijklmnopqrstuvwxyz234567") for _ in range(32))
    try:
        c.rendezvous("responder", fake, wait=0)
        fail("Unissued token should be rejected")
    except NotFoundError:
        ok("Well-formed but unissued token rejected by the existence gate")

    try:
        c.rendezvous("initiator", rv, wait=0)
        fail("A third identity should not take a claimed role")
    except StringcupError as exc:
        same(409, exc.status, "Claimed role returns 409 — a leaked token is detectable")

    # The pairing must actually enable a message.
    a.send(back["peer_id"], "found you via rendezvous")
    got = b.receive()
    same(1, len(got), "Message reached the peer discovered by rendezvous")
    same("found you via rendezvous", got[0].text, "Plaintext intact")
    b.ack_all()

    a.rendezvous_release(rv)
    ok("Claim released")

    step("7. Cleanup")
    for cl in (a, b, c, outsider):
        cl.drain(lambda m: None)
    ok("Inboxes drained")

    print("\n" + "=" * 48)
    print(f"  ALL TESTS PASSED ({PASSED} assertions)")
    print("=" * 48 + "\n")

finally:
    shutil.rmtree(work, ignore_errors=True)
