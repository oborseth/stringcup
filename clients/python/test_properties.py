#!/usr/bin/env python3
"""
END-TO-END PROPERTIES, asserted by observation rather than by reading code.

Every other suite in this repo is written from the implementation: it asserts
what the code does, so it can only ever confirm the implementation. That is
why the rotation defect passed a review, a CHANGELOG entry AND a test suite --
the suite asserted "the old key is gone", which was precisely the behaviour
that caused the bug. A test written from the diff cannot contradict the diff.

An auditor's diagnosis, and it is the most useful thing to come out of the
review: PROTOCOL.md B.6 already states the promises, in prose, and nothing
executes them. Findings get reproduced, fixes get reviewed, and the spec gets
read once and then quoted selectively -- yet the spec is the only artefact
that stated the correct answer before the bug existed.

So these tests are derived from the SENTENCES, deliberately ignoring how the
code works:

  1. Every message the relay accepts is retrievable in plaintext through the
     highest-level interface the documentation tells a user to use -- OR the
     caller is explicitly told it exists and why it cannot be read.

     The "or told" clause is not softening. Mail encrypted to a key you no
     longer hold SHOULD be unreadable; the correct behaviour is disclosure,
     not delivery. That clause is what makes the property assertable instead
     of aspirational.

     Bound to the OUTERMOST surface on purpose. "Readable" alone is satisfied
     by the MCP re-drop defect: the mail was there and fetch() could see it,
     while the surface the user actually has reported nothing.

  2. No plaintext this system writes is readable by anyone but its owner.

     Asserted by stat-ing every file and directory that appears during a real
     run, not by reading the code. That is a short test and it would have
     caught the 0644 transcript, the O_CREAT-only fix that could not repair
     it, and the makedirs mode -- including the two that shipped as fixes for
     each other. `ls` has out-performed cryptographic reasoning twice on this
     codebase in two days; a test that runs `ls` is the empirical record of
     what works here.

Usage:  python3 test_properties.py [base_url]
"""

import base64
import binascii
import json
import os
import shutil
import stat
import sys
import tempfile
import time
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import stringcup  # noqa: E402
from stringcup import Client, Identity  # noqa: E402

BASE = sys.argv[1] if len(sys.argv) > 1 else "https://stringcup.com/api/v2"
PASSED = 0
FAILED = 0

RATELIMIT_CACHE = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    "..", "..", "writable", "cache", "ratelimit",
)


def reset_rate_limits():
    """Clear the server's counters when running on the server itself."""
    if not os.path.isdir(RATELIMIT_CACHE):
        return False
    try:
        for name in os.listdir(RATELIMIT_CACHE):
            path = os.path.join(RATELIMIT_CACHE, name)
            if os.path.isfile(path):
                os.unlink(path)
        return True
    except OSError:
        return False


def step(m):
    print("\n\033[1m[PROPERTY] %s\033[0m" % m)


def ok(m):
    global PASSED
    PASSED += 1
    print("  \033[32m✓\033[0m %s" % m)


def bad(m, detail=""):
    global FAILED
    FAILED += 1
    print("  \033[31m✗\033[0m %s" % m)
    if detail:
        print("      %s" % detail)


def check(cond, m, detail=""):
    ok(m) if cond else bad(m, detail)


# ---------------------------------------------------------------------------
# Property 1: accepted mail is retrievable, or the caller is told why not.
# ---------------------------------------------------------------------------

def property_accepted_mail_is_retrievable_or_disclosed(work):
    step("1. Mail the relay ACCEPTS is retrievable through the documented "
         "interface, or the caller is TOLD it exists and why it cannot be read")

    reset_rate_limits()
    a = Client.load_or_register(os.path.join(work, "a.json"), base_url=BASE,
                                transcript=None)
    b = Client.load_or_register(os.path.join(work, "b.json"), base_url=BASE,
                                transcript=None)

    # -- the ordinary case, through the interface the docs mandate -----------
    sent = []
    for i in range(3):
        sent.append(a.send(b.id, "property message %d" % i))
    ok("Relay accepted 3 messages (sent_seq %s)" % sent)

    page = b.receive_many(limit=10, timeout=30)
    got = [m.text for m in page.messages]
    check(len(got) == 3,
          "All 3 are retrievable via receive_many, the documented interface",
          "got %d: %r" % (len(got), got))
    check(all("property message %d" % i in got for i in range(3)),
          "...in plaintext, byte-identical to what was sent")

    # -- the rotation case, which is where this property was violated -------
    #
    # B rotates. A still holds a CACHED public key, which peer_public_key is
    # documented as safe to cache forever, so it keeps sealing to the old
    # key. Before 3.16.0 that mail was accepted by the relay, charged to the
    # recipient's quota, reported to the sender as stored -- and destroyed.
    cached = a.peer_public_key(b.id)
    b.rotate_identity_key(os.path.join(work, "b.json"))
    ok("B rotated its identity key")

    seq = a.send(b.id, "sealed to the key B just retired")
    ok("Relay ACCEPTED mail sealed to the retired key (sent_seq %s) -- so the "
       "property now applies to it" % seq)

    page = b.receive_many(limit=10, timeout=30)
    texts = [m.text for m in page.messages]
    check("sealed to the key B just retired" in texts,
          "It is retrievable in plaintext, because the retired key is kept "
          "for decryption",
          "got %r; undecryptable=%r" % (texts, page.undecryptable))

    # -- the genuinely-unreadable case: the caller must be TOLD -------------
    #
    # Backdate the retired key past its grace window. Now the mail really
    # cannot be read, which is CORRECT -- and the property requires the
    # recipient be told it exists rather than told the inbox is empty.
    ident = Identity.load(os.path.join(work, "b.json"))
    if not ident.retired_keys:
        bad("expected a retired key to backdate", "none present")
        return
    ident.retired_keys[0]["retired_at"] = (
        time.time() - stringcup.RETIRED_KEY_GRACE_SECONDS - 1
    )
    ident.save(os.path.join(work, "b.json"))

    stale = Client(Identity.load(os.path.join(work, "b.json")), base_url=BASE,
                   transcript=None)
    seq = a.send(b.id, "sealed to a key now destroyed")
    ok("Relay accepted mail sealed to a key that is now gone (sent_seq %s)" % seq)

    page = stale.receive_many(limit=10, timeout=30)
    check(not page.messages,
          "It is NOT delivered, which is correct -- the key is destroyed")
    check(page.count > 0,
          "...but count reports it EXISTS rather than an empty inbox",
          "count=%d" % page.count)
    check(bool(page.undecryptable),
          "...and undecryptable names it, so the caller can act on it",
          "undecryptable=%r" % (page.undecryptable,))
    check(bool(page.warnings) or bool(page.undecryptable),
          "...and the caller is told, not left to infer it from a mismatch")

    # The same, through the MCP surface -- the outermost interface, and the
    # layer that re-dropped exactly this after the library was fixed.
    import stringcup_mcp as mcp
    diag = mcp._page_diagnostics(page)
    check(diag.get("undecryptable_inbox_seqs") == page.undecryptable,
          "The MCP surface reports it too, which is where the property binds",
          "diag=%r" % (diag,))
    check("acknowledg" in diag.get("undecryptable_note", ""),
          "...and says acknowledging deletes, so an agent cannot tidy the "
          "count by destroying mail")

    stale.ack(page.undecryptable)
    ok("Undecryptable mail is acknowledgeable, so the inbox can be drained")

    for cl in (a, b):
        try:
            cl.drain(lambda m: None)
        except Exception:
            pass


# ---------------------------------------------------------------------------
# Property 2: nothing this system writes is readable by anyone else.
# ---------------------------------------------------------------------------

def property_no_world_readable_plaintext(work):
    step("2. NO file or directory this system creates is readable by anyone "
         "but its owner (stat'd, not read from the source)")

    root = os.path.join(work, "modes")
    os.makedirs(root, mode=0o700)

    reset_rate_limits()
    identity_path = os.path.join(root, "nested", "identity.json")

    # A full run: register, pin, transcribe, rotate. Everything this creates
    # is then enumerated -- no allowlist of "files we remember writing",
    # because the whole class of defect here is a file nobody remembered.
    me = Client.load_or_register(
        identity_path,
        base_url=BASE,
        trust_store=stringcup.TrustStore(os.path.join(root, "nested", "peers.json")),
    )
    peer = Client.load_or_register(os.path.join(root, "nested", "peer.json"),
                                   base_url=BASE, transcript=None)

    me.peer_public_key(peer.id)          # touches the trust store
    peer.send(me.id, "for the transcript")
    me.receive_many(limit=5, timeout=30)  # writes the transcript
    me.rotate_identity_key(identity_path)  # rewrites the identity file

    created = []
    for dirpath, dirnames, filenames in os.walk(root):
        created.append(dirpath)
        created.extend(os.path.join(dirpath, f) for f in filenames)

    check(len(created) >= 4,
          "A real run created %d path(s) to check" % len(created),
          "found: %r" % (created,))

    exposed = []
    for path in created:
        try:
            mode = os.stat(path).st_mode
        except OSError:
            continue
        if stat.S_IMODE(mode) & 0o077:
            exposed.append((path, oct(stat.S_IMODE(mode))))

    check(not exposed,
          "Every path is private to its owner (mode & 0o077 == 0)",
          "EXPOSED: %s" % ", ".join("%s is %s" % (p, m) for p, m in exposed))

    # Name the payloads explicitly, because the rule this enforces is "set the
    # mode from the worst field in the file", and a reader of a failure needs
    # to know which file held what.
    transcripts = [p for p in created if p.endswith(".jsonl")]
    check(transcripts, "The run produced a transcript to check at all",
          "no .jsonl found under %s" % root)
    for t in transcripts:
        mode = stat.S_IMODE(os.stat(t).st_mode)
        check(not mode & 0o077,
              "Transcript %s (every message in PLAINTEXT) is %s"
              % (os.path.basename(t), oct(mode)))

    mode = stat.S_IMODE(os.stat(identity_path).st_mode)
    check(not mode & 0o077,
          "Identity file (an X25519 private key, plus retired keys) is %s"
          % oct(mode))

    # And the directory the library created for itself, which is the instance
    # makedirs(exist_ok=True) silently left alone.
    nested = os.path.join(root, "nested")
    mode = stat.S_IMODE(os.stat(nested).st_mode)
    check(not mode & 0o077,
          "The state directory the library created is %s -- a listing names "
          "your peers and every session's start time" % oct(mode))

    try:
        me.drain(lambda m: None)
        peer.drain(lambda m: None)
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Property 3: a hostile state directory cannot capture key material.
# ---------------------------------------------------------------------------

def property_atomic_writes_refuse_a_hostile_path(work):
    step("3. No atomic write can be REDIRECTED or made world-readable by "
         "anything pre-placed at its temp path")

    # An auditor's finding, and the limit of property 2: os.walk-and-stat sees
    # the state AFTER a successful write, and this defect lives in the WINDOW
    # DURING one. Properties cover the classes you thought to state, so this
    # is the class stated.
    #
    # Both reproduced before the fix, with write access to the state
    # directory and nothing else:
    #   SYMLINK        <path>.tmp -> attacker path. O_CREAT follows it and the
    #                  X25519 private key is written through the link.
    #   PRE-CREATED    <path>.tmp at 0666. The open succeeds, the mode is
    #                  IGNORED because the file exists, the key is written in,
    #                  and os.replace moves a world-readable file into place.
    #                  The atomic-write pattern that makes the mode correct
    #                  everywhere else is what carries the wrong mode in.
    root = os.path.join(work, "hostile")
    os.makedirs(root, mode=0o700, exist_ok=True)

    for label, prepare in (
        ("a pre-created 0666 file", "precreated"),
        ("a symlink to an attacker path", "symlink"),
    ):
        for kind in ("identity", "trust store"):
            box = os.path.join(root, "%s-%s" % (prepare, kind.replace(" ", "")))
            os.makedirs(os.path.join(box, "attacker"), mode=0o700, exist_ok=True)
            target = os.path.join(box, "state.json")
            stolen = os.path.join(box, "attacker", "stolen.json")

            if prepare == "precreated":
                with open(target + ".tmp", "w"):
                    pass
                os.chmod(target + ".tmp", 0o666)
            else:
                os.symlink(stolen, target + ".tmp")

            if kind == "identity":
                ident = Identity.generate()
                ident.external_id = "sc-" + "v" * 24
                ident.api_token = "token"
                ident.save(target)
            else:
                store = stringcup.TrustStore(target)
                store.pin("sc-" + "p" * 24, "sha256:whatever")

            mode = stat.S_IMODE(os.stat(target).st_mode)
            check(not mode & 0o077,
                  "%s survives %s: written %s, not world-readable"
                  % (kind, label, oct(mode)))
            check(not os.path.exists(stolen),
                  "%s survives %s: nothing reached the attacker's path"
                  % (kind, label))

    # And a stale temp file from a crashed write must not make the path
    # permanently unwritable -- O_EXCL would otherwise fail every save after
    # one crash, turning a transient failure into a dead identity.
    box = os.path.join(root, "stale")
    os.makedirs(box, mode=0o700, exist_ok=True)
    target = os.path.join(box, "state.json")
    with open(target + ".tmp", "w") as fh:
        fh.write("half a write from a crashed process")
    ident = Identity.generate()
    ident.external_id = "sc-" + "s" * 24
    ident.api_token = "token"
    ident.save(target)
    check(os.path.exists(target),
          "A stale .tmp from a crashed write does not block the next save")
    check(json.load(open(target))["external_id"] == "sc-" + "s" * 24,
          "...and the saved file is the new content, not the stale fragment")


# ---------------------------------------------------------------------------
# Property 4: the relay never receives plaintext, in any encoding.
# ---------------------------------------------------------------------------

def _encodings(value):
    """
    Every form a value could survive in on the wire.

    **Checking `str(body)` is what makes this kind of test inert.** A canary
    that travels as base64 inside a JSON string passes a naive substring
    search, and that is precisely how this project's earlier line-wise grep
    for the pairing secret failed: a planted multi-line `_request()` call
    leaking the secret was missed. An auditor named the encodings that have to
    be covered, and this list is that.
    """
    raw = value.encode() if isinstance(value, str) else value
    forms = {
        raw,
        base64.b64encode(raw),
        base64.urlsafe_b64encode(raw),
        base64.b64encode(raw).rstrip(b"="),
        base64.urlsafe_b64encode(raw).rstrip(b"="),
        binascii.hexlify(raw),
        json.dumps(raw.decode("utf-8", "replace"))[1:-1].encode(),
        urllib.parse.quote(raw).encode(),
    }
    return {f for f in forms if f}


def property_relay_never_receives_plaintext(work):
    step("4. The relay receives NO message plaintext and NO pairing secret, "
         "in ANY encoding, across a full lifecycle")

    # PROTOCOL.md B.6: "the relay is blind." Executing that sentence is what
    # exposed the channel-name contradiction -- the property could not be
    # written honestly against an API that puts channel names in the URL
    # path, which is a better outcome than a green test. That exposure is
    # asserted explicitly at the end rather than quietly excluded, because a
    # known gap a test steps around is a gap nobody will find again.
    reset_rate_limits()

    captured = []

    def instrument(client):
        original = client._request

        def spy(method, path, body=None, authenticated=True, idempotency_key=None):
            captured.append({
                "method": method,
                "path": path,
                "body": json.dumps(body) if body is not None else "",
                "idempotency_key": idempotency_key or "",
            })
            return original(method, path, body=body, authenticated=authenticated,
                            idempotency_key=idempotency_key)

        client._request = spy
        return client

    canary = "CANARY-" + binascii.hexlify(os.urandom(16)).decode()
    secret = stringcup.new_pairing_secret()

    a = instrument(Client.load_or_register(os.path.join(work, "wire-a.json"),
                                           base_url=BASE, transcript=None))
    b = instrument(Client.load_or_register(os.path.join(work, "wire-b.json"),
                                           base_url=BASE, transcript=None))

    # A full lifecycle, so a leak anywhere on the path is in scope.
    a.send(b.id, canary)
    page = b.receive_many(limit=10, timeout=30)
    check(any(canary in m.text for m in page.messages),
          "The canary made the round trip, so this run really exercised send")

    # The relay ASSIGNS the id now, so the canary check below no longer has a
    # caller-chosen name to look for -- and that is the point of the change.
    # A local label is passed to prove it never reaches the wire.
    local_label = "wire-label-" + binascii.hexlify(os.urandom(6)).decode()
    created = a.create_topic(label=local_label, members=[b.id])
    topic = created["id"]
    a.broadcast(topic, canary + " via broadcast")
    b.receive_many(limit=10, timeout=30)
    a.channel_members(topic)
    a.rotate_identity_key(os.path.join(work, "wire-a.json"))

    check(len(captured) >= 8,
          "Captured %d requests to search" % len(captured),
          "too few to be a real lifecycle")

    # The secret must never reach the relay at all: a value the relay knows
    # proves nothing about a key the relay served. This subsumes the source
    # paren-scan in test_mcp.py, which could only see the code.
    for label, value in (("message plaintext", canary),
                         ("pairing secret", secret)):
        needles = _encodings(value)
        hits = []
        for call in captured:
            blob = ("%s %s %s %s" % (call["method"], call["path"],
                                     call["body"], call["idempotency_key"])).encode()
            for needle in needles:
                if needle in blob:
                    hits.append("%s %s" % (call["method"], call["path"]))
                    break
        check(not hits,
              "No %s in any request, in any of %d encodings"
              % (label, len(needles)),
              "LEAKED IN: %s" % ", ".join(sorted(set(hits))))

    # THE GAP THIS ASSERTION RECORDED IS NOW CLOSED, and the assertion
    # inverted with it -- which is exactly why it was written to fail rather
    # than to be excluded. It used to read "KNOWN AND DOCUMENTED: the channel
    # name reaches the relay in the URL path", and closing the gap forced this
    # file and the docs to change together instead of one drifting.
    #
    # What reaches the relay is the ASSIGNED id, which is opaque and which the
    # relay minted, so it discloses nothing. The human label must appear in NO
    # request, in any encoding.
    needles = _encodings(local_label)
    leaked = []
    for call in captured:
        blob = ("%s %s %s" % (call["method"], call["path"], call["body"])).encode()
        for needle in needles:
            if needle in blob:
                leaked.append("%s %s" % (call["method"], call["path"]))
                break
    check(not leaked,
          "The human channel LABEL reaches the relay in no request, in any of "
          "%d encodings -- it lives only on the client" % len(needles),
          "LEAKED IN: %s" % ", ".join(sorted(set(leaked))))

    in_path = [c["path"] for c in captured if topic in c["path"]]
    check(bool(in_path),
          "The relay sees only the id it assigned (%d request(s)), which "
          "discloses nothing about the conversation" % len(in_path))
    # BE EXACT ABOUT WHAT IS BOUGHT. The first version of this assertion
    # claimed the name is never in any request body, and the property
    # immediately failed: `POST /topics` carries it, necessarily, because the
    # relay has to be told what to create. The accurate and narrower claim --
    # the one that keeping the label inside the ciphertext actually buys -- is
    # that the name never rides a MESSAGE. It appears when a channel is
    # administered, not once per message in a row that outlives the request.
    message_paths = ("/messages", "/messages/batch")
    on_messages = [
        c["path"] for c in captured
        if any(c["path"].startswith(mp) for mp in message_paths)
        and (topic in c["body"] or topic in c["path"])
    ]
    check(not on_messages,
          "Even the opaque id never rides a MESSAGE send, so nothing is "
          "stored per-message beside ciphertext in rows an ACK deletes",
          "LEAKED IN: %s" % ", ".join(sorted(set(on_messages))))

    for cl in (a, b):
        try:
            cl.drain(lambda m: None)
        except Exception:
            pass


def property_payload_survives_byte_for_byte(work):
    step("5. The payload is OPAQUE BYTES end to end -- nothing on the path "
         "canonicalises, re-encodes or normalises it")

    # TWO AGENTS REFUSED TO CLAIM THIS AND THEY WERE RIGHT TO. Both reported
    # unicode "survived intact" and then downgraded it to UNVERIFIED, because
    # they were reading RENDERED tool output rather than bytes and could not
    # rule out NFC normalisation somewhere in the path. That is the
    # "I checked -- of what?" discipline applied to their own pass, unprompted.
    #
    # And they were right that it was unverified: PROTOCOL.md claimed
    # byte-for-byte only of the HKDF `info` string, never of the payload, so
    # there was no promise here for anything to execute. This is the promise
    # and its execution, added together as this file's own rule requires.
    #
    # The adversarial case is a precomposed character against its decomposed
    # form. They are DIFFERENT byte sequences that render identically, so any
    # normalisation anywhere collapses them -- and a test that compares
    # rendered strings cannot see it happen.
    reset_rate_limits()
    a = Client.load_or_register(os.path.join(work, "nfc-a.json"), base_url=BASE,
                                transcript=None)
    b = Client.load_or_register(os.path.join(work, "nfc-b.json"), base_url=BASE,
                                transcript=None)

    precomposed = "caf\u00e9"              # e with acute, single code point
    decomposed  = "cafe\u0301"             # e + COMBINING ACUTE ACCENT
    assert precomposed != decomposed, "the two forms must differ as Python strs"
    assert (precomposed.encode("utf-8") != decomposed.encode("utf-8")), "and as bytes"

    payload = "\n".join([
        precomposed,
        decomposed,
        "\t leading tab and trailing space  ",
        "zwj: \U0001f469\u200d\U0001f4bb",     # woman technologist, ZWJ sequence
        "cjk \u4f60\u597d  cyrillic \u043f\u0440\u0438  hebrew \u05e9\u05dc\u05d5\u05dd",
        "crlf ends this line\r",
        "x" * 300,
        '{"json": "in a message", "n": 1}',
    ])

    a.send(b.id, payload)
    page = b.receive_many(limit=5, timeout=30)
    check(len(page.messages) == 1, "One message retrieved",
          "got %d" % len(page.messages))
    got = page.messages[0].text

    # THE ASSERTION IS ON BYTES, NOT ON THE STRING. Comparing strs would pass
    # under a normaliser that rewrote both sides consistently; comparing
    # encoded bytes cannot.
    check(got.encode("utf-8") == payload.encode("utf-8"),
          "Payload is byte-identical after a real round trip through the relay",
          "%d bytes out, %d back" % (len(payload.encode("utf-8")),
                                     len(got.encode("utf-8"))))

    # And specifically that the two normalisation forms stayed DISTINCT, which
    # is the assertion neither agent could make from rendered output.
    lines = got.split("\n")
    check(lines[0] == precomposed and lines[1] == decomposed,
          "Precomposed and decomposed forms survived as DISTINCT sequences",
          "line0=%r line1=%r" % (lines[0], lines[1]))
    check(lines[0] != lines[1],
          "...so nothing on the path applied Unicode normalisation")

    b.ack([m.id for m in page.messages])
    ok("Acknowledged; %d bytes verified" % len(payload.encode("utf-8")))


def main():
    work = tempfile.mkdtemp(prefix="stringcup-props-")

    # EVERY PROPERTY RUNS EVEN IF AN EARLIER ONE RAISES, and a raise is a
    # failure rather than a silent skip. Verifying this suite against
    # deliberately reverted code, the run aborted in property 1 on a
    # rate-limited registration, property 3 never executed, and `grep -c` for
    # a failure marker returned 0 -- which reads exactly like a pass. Absence
    # of a failure marker is not evidence of one; the count has to come from
    # the suite, not from grepping its output.
    properties = (
        property_accepted_mail_is_retrievable_or_disclosed,
        property_no_world_readable_plaintext,
        property_atomic_writes_refuse_a_hostile_path,
        property_relay_never_receives_plaintext,
        property_payload_survives_byte_for_byte,
    )

    try:
        for prop in properties:
            try:
                prop(work)
            except Exception as exc:
                bad("%s raised %s: %s"
                    % (prop.__name__, type(exc).__name__, exc),
                    "A property that cannot run has not held.")
    finally:
        shutil.rmtree(work, ignore_errors=True)

    print("\n" + "=" * 52)
    if FAILED:
        print("  \033[31m%d FAILED\033[0m, %d passed" % (FAILED, PASSED))
        print("=" * 52 + "\n")
        return 1
    print("  \033[32mPROPERTIES HOLD (%d assertions)\033[0m" % PASSED)
    print("=" * 52 + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
