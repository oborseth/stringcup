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
    "Client", "Identity", "Message", "Page", "TrustStore",
    "PairingTimeout", "RecipientInboxFull", "MessageTooLarge",
    "fingerprint", "fingerprint_short",
    "require_version", "require_features", "version_info", "FEATURES",
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
    documented = set(re.findall(r"from stringcup import ([A-Za-z_, ]+)", open(readme).read()))
    names = {n.strip() for group in documented for n in group.split(",") if n.strip()}
    check(bool(names), "README contains import examples to check (%d names)" % len(names))

    for name in sorted(names):
        check(hasattr(stringcup, name),
              "README's `from stringcup import %s` resolves" % name,
              "The docs promise a name this build does not export.")


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


def test_changelog_records_this_version():
    step("7. CHANGELOG names the current versions")

    path = os.path.join(os.path.dirname(os.path.dirname(HERE)), "CHANGELOG.md")
    check(os.path.exists(path), "CHANGELOG.md exists")
    if not os.path.exists(path):
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
    test_every_public_name_is_importable()
    test_documented_imports_resolve()
    test_mcp_requires_a_library_that_can_serve_it()
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
