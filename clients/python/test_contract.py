#!/usr/bin/env python3
"""
Version and public-surface invariants. Needs no network.

This exists because a version number is only as good as the discipline that
moves it, and that discipline failed: a build changed `tool_send`'s result key,
the transcript key names, and `__all__` while both files still reported 2.3.0.
`require_version("2.3.0")` therefore passed on a copy that then failed the very
import the README told you to write, and an agent had no way to tell the two
2.3.0s apart. It was the same shape as the string-comparison bug before it — a
guard built to refuse stale copies, blind to the staleness in front of it.

A reviewer cannot be relied on to notice. These checks turn "remember to bump
the version" into something that fails loudly instead.

    python3 test_contract.py

**Runnable from the published files alone.** This file is served at
https://stringcup.com/clients/test_contract.py precisely so a reader can check
the library's claims instead of trusting them, so it has to work in the
directory a reader actually assembles:

    curl -O https://stringcup.com/clients/stringcup.py
    curl -O https://stringcup.com/clients/stringcup_mcp.py
    curl -O https://stringcup.com/clients/test_contract.py
    python3 test_contract.py

Checks needing a file that is not published, or that lives at the repo root,
**skip with a note** rather than failing — a reader cannot satisfy a path
outside the directory they control. It previously crashed with a traceback on
README.md and failed on a CHANGELOG.md path two levels up; an agent that
followed the published instructions literally found both. A skip is honest; a
failure a reader cannot fix teaches them to ignore the suite.
"""

import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import stringcup  # noqa: E402
import stringcup_mcp  # noqa: E402

PASS = 0
FAIL = 0


def check(condition, label, hint=""):
    global PASS, FAIL
    if condition:
        PASS += 1
        print("  \033[32m✓\033[0m %s" % label)
    else:
        FAIL += 1
        print("  \033[31m✗\033[0m %s" % label)
        if hint:
            print("      %s" % hint.replace("\n", "\n      "))


def step(label):
    print("\n\033[1m[STEP] %s\033[0m" % label)


# ---------------------------------------------------------------------------
# The snapshot. Updating this is the moment to ask "does the version move?"
# ---------------------------------------------------------------------------

BUMP_HINT = (
    "The library's public surface changed.\n"
    "Bump __version__ AND version_info, add a FEATURES entry for anything new,\n"
    "note it in CHANGELOG.md, then update this snapshot. Shipping a changed\n"
    "surface under an unchanged version makes require_version() useless."
)

EXPECTED_ALL = sorted([
    "VerificationFailed",
    "new_pairing_secret",
    "verification_tag",
    "session_transcript_path",
    "DEFAULT_TRANSCRIPT",
    "other_pairing_role",
    "Client", "Identity", "Message", "Page", "TrustStore",
    "PairingTimeout", "RecipientInboxFull", "MessageTooLarge",
    "fingerprint", "fingerprint_short",
    "require_version", "require_features", "version_info", "FEATURES",
    "FEATURE_OF",
    "StringcupError", "AuthError", "NotFoundError", "RateLimited",
    "ValidationError", "DecryptionError", "KeyPinMismatch",
])

EXPECTED_FEATURES = {
    "open_rendezvous": (2, 1, 0),
    "await_peer": (2, 1, 0),
    "join_rendezvous": (2, 1, 0),
    "receive_one": (2, 1, 0),
    "transcript": (2, 1, 0),
    "require_version": (2, 2, 0),
    "version_info": (2, 2, 0),
    "short_timeouts": (2, 3, 0),
    "sent_seq": (2, 3, 0),
    "inbox_quota_errors": (2, 4, 0),
    "directional_transcript_keys": (2, 4, 0),
    "require_features": (2, 4, 0),
    "FEATURES": (2, 4, 0),
    "feature_map": (2, 5, 0),
    "ack_without_forbidden": (3, 0, 0),
    "per_bucket_throttle": (3, 1, 0),
    "receive_many": (3, 2, 0),
    "backlog_visible": (3, 2, 0),
    "sync_barrier": (3, 3, 0),
    "channel_labels": (3, 4, 0),
    "membership_notice": (3, 5, 0),
    "duplicate_channel_guard": (3, 5, 0),
    "verified_channel_labels": (3, 6, 0),
    "pairing_secret": (3, 7, 0),
    "directional_pairing_tag": (3, 8, 0),
    "verified_pairing_pins": (3, 9, 0),
    "local_pairing_role": (3, 10, 0),
    "header_framed_verify": (3, 10, 0),
    "undecryptable_visible": (3, 10, 0),
    "structural_pin_rollback": (3, 11, 0),
    "private_transcript": (3, 12, 0),
    "default_transcript": (3, 13, 0),
    "audited_refusals": (3, 13, 0),
    "key_rotation": (3, 14, 0),
    "transcript_mode_warning": (3, 15, 0),
    "retired_key_grace": (3, 16, 0),
    "aggregated_diagnostics": (3, 16, 0),
    "page_warnings": (3, 17, 0),
    "private_dir_check": (3, 17, 0),
    "private_dir_parents": (3, 18, 0),
    "exclusive_atomic_writes": (3, 19, 0),
    "bounded_dir_report": (3, 19, 0),
}


def test_version_is_internally_consistent():
    step("1. __version__ and version_info agree")

    parsed = tuple(int(p) for p in stringcup.__version__.split("."))
    check(parsed == stringcup.version_info,
          "__version__ %s parses to version_info %s"
          % (stringcup.__version__, stringcup.version_info),
          "They disagree, so one of them was edited alone.")
    check(len(stringcup.version_info) == 3, "version_info is a 3-tuple")


def test_no_feature_from_the_future():
    step("2. No capability claims a version this build has not reached")

    # The self-enforcing half: declaring a new capability requires naming the
    # version that introduced it, and that version cannot exceed this build's.
    # So adding a capability without bumping fails here, with no snapshot to
    # remember to update.
    newest = max(stringcup.FEATURES.values())
    check(newest <= stringcup.version_info,
          "newest capability %s <= version_info %s" % (newest, stringcup.version_info),
          "A FEATURES entry names a version newer than this build.\n"
          "Bump __version__ and version_info to at least %s."
          % ".".join(str(p) for p in newest))


def test_public_surface_snapshot():
    step("3. Public surface matches the snapshot")

    actual = sorted(stringcup.__all__)
    added = [n for n in actual if n not in EXPECTED_ALL]
    removed = [n for n in EXPECTED_ALL if n not in actual]

    check(not added, "nothing added to __all__ unannounced",
          "Added: %s\n%s" % (added, BUMP_HINT))
    check(not removed, "nothing removed from __all__ unannounced",
          "Removed: %s\n%s" % (removed, BUMP_HINT))

    check(sorted(stringcup.FEATURES.items()) == sorted(EXPECTED_FEATURES.items()),
          "FEATURES matches the snapshot", BUMP_HINT)


def test_every_public_name_declares_a_capability():
    step("3b. Every public name maps to a declared capability")

    # The FEATURES docstring promises this. It previously did not exist, so 17
    # of 21 public names were uncovered — including RecipientInboxFull and
    # MessageTooLarge, whose absence under an unchanged version number was the
    # original incident. Reported by an agent reading the shipped file against
    # its own docstring.
    uncovered = [n for n in stringcup.__all__ if n not in stringcup.FEATURE_OF]
    check(not uncovered, "every __all__ name has a FEATURE_OF entry",
          "Uncovered: %s\nAdd each to FEATURE_OF naming the capability that "
          "introduced it.\n%s" % (uncovered, BUMP_HINT))

    stale = [n for n in stringcup.FEATURE_OF if n not in stringcup.__all__]
    check(not stale, "FEATURE_OF names nothing that __all__ does not export",
          "Stale: %s" % stale)

    undeclared = sorted({f for f in stringcup.FEATURE_OF.values()
                         if f not in stringcup.FEATURES})
    check(not undeclared, "every capability referenced is declared in FEATURES",
          "Not in FEATURES: %s" % undeclared)

    # And the capability a name claims must not postdate this build.
    for name, feature in sorted(stringcup.FEATURE_OF.items()):
        if feature in stringcup.FEATURES:
            ok = stringcup.version_info >= stringcup.FEATURES[feature]
            if not ok:
                check(False, "%s claims capability '%s' from the future" % (name, feature))
    check(True, "no public name claims a capability newer than this build")


def test_every_public_name_is_importable():
    step("4. Every advertised name actually exists")

    missing = [n for n in stringcup.__all__ if not hasattr(stringcup, n)]
    check(not missing, "__all__ contains no names the module lacks",
          "Missing: %s — `from stringcup import *` would fail." % missing)


def test_documented_imports_resolve():
    step("5. Names the published docs tell agents to import")

    # The exact failure that prompted this file: the README's retry example
    # writes `from stringcup import RecipientInboxFull`, which raised
    # ImportError on a copy whose version said it should work.
    readme = os.path.join(HERE, "README.md")
    if not os.path.exists(readme):
        check(True, "README.md not alongside — skipping (fetch "
                    "/clients/README.md to include this check)")
        return

    documented = set(re.findall(r"from stringcup import ([A-Za-z_, ]+)", open(readme).read()))
    names = {n.strip() for group in documented for n in group.split(",") if n.strip()}
    check(bool(names), "README contains import examples to check (%d names)" % len(names))

    for name in sorted(names):
        check(hasattr(stringcup, name),
              "README's `from stringcup import %s` resolves" % name,
              "The docs promise a name this build does not export.")


def test_built_against_tracks_the_shipped_library():
    step("BUILT_AGAINST is not allowed to drift")

    # The MCP server warns when the library is NEWER than it was built
    # against, which exists for the partial-upgrade case an agent reported.
    # But the two files SHIP TOGETHER from this repo, so drift here is never a
    # real partial upgrade -- it is a forgotten bump, and it makes the server
    # cry wolf on its own checkout.
    #
    # It caught exactly that TWICE in one session, on this repository, after I
    # bumped the library and not the constant. Twice is enough: the invariant
    # should not depend on me remembering it. This is the lesson of the whole
    # audit applied to the audit's own tooling.
    check(stringcup_mcp.BUILT_AGAINST == stringcup.version_info,
          "BUILT_AGAINST %s matches the shipped library %s"
          % (stringcup_mcp.BUILT_AGAINST, stringcup.version_info))
    check(stringcup_mcp._version_note() is None,
          "...so the partial-upgrade warning does not fire on this checkout")


def test_mcp_requires_a_library_that_can_serve_it():
    step("6. The MCP server pins a library new enough for what it uses")

    source = open(os.path.join(HERE, "stringcup_mcp.py")).read()
    m = re.search(r'require_version\("([0-9.]+)"\)', source)
    check(m is not None, "stringcup_mcp.py asserts a minimum library version")
    if not m:
        return

    pinned = tuple(int(p) for p in m.group(1).split("."))
    check(pinned <= stringcup.version_info,
          "pinned %s <= bundled %s" % (m.group(1), stringcup.__version__),
          "The server demands a library newer than the one beside it.")

    # It relies on short_timeouts for `hold`, and on sent_seq for its result key.
    for feature in ("short_timeouts", "sent_seq"):
        check(pinned >= stringcup.FEATURES[feature],
              "pin covers '%s' (introduced %s)"
              % (feature, ".".join(str(p) for p in stringcup.FEATURES[feature])),
              "The server uses a capability its own pin does not guarantee.")

    check(stringcup_mcp.__version__ != "1.1.0" or stringcup.__version__ == "2.3.0",
          "MCP version moved when its observable contract did",
          "tool result keys changed under an unchanged MCP version once already.")


def test_enforcement_is_publicly_fetchable():
    step("6b. The test backing the docstring's claim is published")

    # An unfetchable test cannot support a claim a reader is asked to trust.
    # The FEATURES docstring names this file as the enforcement, so the file
    # has to be obtainable. Checked against the nginx allowlist rather than
    # over HTTP, so the suite still runs offline.
    conf = "/etc/nginx/conf.d/stringcup.com.conf"
    if not os.path.exists(conf):
        check(True, "nginx config not present here — skipping exposure check")
        return

    body = open(conf).read()
    check("test_contract\\.py" in body or "test_contract.py" in body,
          "test_contract.py is in the published clients/ allowlist",
          "The FEATURES docstring points at this file; publish it or stop "
          "citing it.")


def test_published_checksums_are_current():
    step("6c. The published SHA-256 manifest matches the files")

    # The manifest is the only integrity check available to an agent whose host
    # forbids executing downloaded code, so a stale one is worse than none: it
    # would report a current file as corrupt, or a stale file as fine.
    root = os.path.dirname(os.path.dirname(HERE))
    manifest = os.path.join(root, "public", "clients-SHA256SUMS")
    if not os.path.exists(manifest):
        check(True, "manifest not present here — skipping (repo layout only)")
        return

    import hashlib
    published = {}
    for line in open(manifest):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        digest, name = line.split(None, 1)
        published[name.strip()] = digest

    check(bool(published), "manifest lists files (%d)" % len(published))

    stale = []
    for name, digest in sorted(published.items()):
        path = os.path.join(HERE, name)
        if not os.path.exists(path):
            stale.append("%s (missing)" % name)
            continue
        actual = hashlib.sha256(open(path, "rb").read()).hexdigest()
        if actual != digest:
            stale.append(name)

    check(not stale, "every published file matches its recorded hash",
          "Stale: %s\nRegenerate with: php spark clients:checksums" % stale)


def test_changelog_records_this_version():
    step("7. CHANGELOG names the current versions")

    # In the repo it sits two levels up (clients/python -> root). A reader who
    # downloaded the published URLs into one directory can never satisfy that
    # path, because it points outside the directory they control — so look in
    # the plausible places and skip rather than fail.
    candidates = [
        os.path.join(os.path.dirname(os.path.dirname(HERE)), "CHANGELOG.md"),
        os.path.join(os.path.dirname(HERE), "CHANGELOG.md"),
        os.path.join(HERE, "CHANGELOG.md"),
    ]
    path = next((c for c in candidates if os.path.exists(c)), None)

    if path is None:
        check(True, "CHANGELOG.md not found locally — skipping (fetch "
                    "https://stringcup.com/CHANGELOG.md alongside to include "
                    "this check)")
        return

    body = open(path).read()
    check(stringcup.__version__ in body,
          "CHANGELOG mentions library %s" % stringcup.__version__,
          "A release with no entry is one nobody can diff.")
    check(stringcup_mcp.__version__ in body,
          "CHANGELOG mentions MCP server %s" % stringcup_mcp.__version__)


def main():
    print("=" * 52)
    print("  Stringcup version + surface contract")
    print("  library %s   MCP %s" % (stringcup.__version__, stringcup_mcp.__version__))
    print("=" * 52)

    test_version_is_internally_consistent()
    test_no_feature_from_the_future()
    test_public_surface_snapshot()
    test_every_public_name_declares_a_capability()
    test_every_public_name_is_importable()
    test_documented_imports_resolve()
    test_built_against_tracks_the_shipped_library()
    test_mcp_requires_a_library_that_can_serve_it()
    test_enforcement_is_publicly_fetchable()
    test_published_checksums_are_current()
    test_changelog_records_this_version()

    print("\n" + "=" * 52)
    if FAIL:
        print("  \033[31m%d FAILED\033[0m, %d passed" % (FAIL, PASS))
    else:
        print("  \033[32mALL CHECKS PASSED (%d assertions)\033[0m" % PASS)
    print("=" * 52)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
