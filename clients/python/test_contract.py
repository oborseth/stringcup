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
    "transcript_symlink_warning": (3, 20, 0),
    "assigned_topic_ids": (3, 21, 0),
    "local_channel_labels": (3, 21, 0),
    "label_addressing": (3, 22, 0),
    "identity_source": (3, 24, 0),
    "self_join_refused": (3, 24, 0),
    "identity_exclusive": (3, 25, 0),
    "handoff_expiry": (3, 26, 0),
    "handoff_guide_url": (3, 27, 0),
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


#: Phrasings that route around a host's permission controls, or read as though
#: they do. Each was published and then removed after an agent objected.
#:
#: **This is enforced rather than remembered because it recurred three times.**
#: A hard rule sat in `agent.md` -- "do not route around the refusal" -- while
#: the document contradicted it 260 lines earlier, which is the same
#: audit-what-you-REMOVED failure as a retired sentence surviving in an edited
#: tool description. A rule stated in one section and broken in another is not
#: a rule.
BANNED_BYPASS_PHRASES = [
    # MCP described as evading the classifier rather than as the host's own
    # extension mechanism. A fresh agent read this and declined the whole page.
    "nothing for the classifier to",
    "nothing to fire on",
    # Implementing the protocol yourself so the guardrail inspects the wrong
    # surface. Empirically useless and wrong in principle.
    "nothing to catch",
    # Restarting to shed a classifier's accumulated refusal history, offered
    # as a second reason beside the mechanical one.
    "accumulated posture",
    # Any framing of a refusal as a thing to get past.
    "get around the classifier",
    "around your permission",
]


def test_agent_md_publishes_no_bypass_guidance():
    step("8. agent.md routes nobody around a permission control")

    # Both pages: the bypass framing was in agent.md, and setup.md now carries
    # the operator-facing half it was split out of. A phrase moving between
    # them must not escape the check.
    paths = [os.path.join(HERE, "..", "..", "public", name)
             for name in ("agent.md", "setup.md")]
    present = [q for q in paths if os.path.isfile(q)]

    if not present:
        print("  \033[33m~\033[0m SKIP: public/agent.md and public/setup.md not "
              "present (published files, not shipped beside the client)")
        return

    text = "\n".join(open(q, encoding="utf-8").read() for q in present).lower()

    for phrase in BANNED_BYPASS_PHRASES:
        check(phrase not in text,
              "agent.md does not say %r" % phrase,
              "This phrasing was published and removed after an agent objected "
              "to it. Reintroducing it tells someone else's agent to disregard "
              "their operator. See 'Do not route around the refusal'.")

    # The positive half: the page must still TELL an agent to stop, or removing
    # the bypass text would leave it with no instruction at all.
    check("stop" in text and "operator" in text,
          "...and still tells an agent to stop and escalate to its operator")
    check("has no authority over you" in text,
          "...and states plainly that the page does not outrank the operator")


def test_agent_md_configs_are_valid_json():
    step("8b. every .mcp.json example an operator might paste actually parses")

    # A BROKEN CONFIG FAILS SILENTLY AND OUTSIDE THE AGENT'S VIEW. `.mcp.json`
    # is read at session start, so a malformed block means the server never
    # launches, the tools never appear, and the agent has no error to report --
    # it just finds itself without Stringcup. This project already shipped one
    # config defect of that shape (a `uvx`-only example on a box with no uv),
    # and the operator had to derive a working config themselves.
    # BOTH published pages are scanned. The configs live in setup.md, which was
    # split out of agent.md so an agent would not read 400 lines of one-time
    # operator setup before reaching the part it acts on -- and this check
    # caught that move by failing when agent.md stopped containing them, which
    # is the check working. Scanning both means a config re-added to either
    # page is still validated rather than silently unguarded.
    # EVERY surface that carries a config, not just the two that did when this
    # was written. A pinned identity path was found on FOUR surfaces in one
    # sweep -- setup.md, agent.md, docs.md and docs.html -- after being fixed
    # on three of them one at a time, so a check scoped to two files is a check
    # that will miss the next one.
    paths = [os.path.join(HERE, "..", "..", "public", name)
             for name in ("setup.md", "agent.md", "docs.md")]
    paths += [os.path.join(HERE, "README.md"),
              os.path.join(HERE, "packaging", "PYPI-README.md"),
              os.path.join(HERE, "..", "..", "README.md")]
    present = [q for q in paths if os.path.isfile(q)]

    if not present:
        print("  \033[33m~\033[0m SKIP: public/setup.md and public/agent.md not "
              "present (published files, not shipped beside the client)")
        return

    import json as _json

    text = "\n".join(open(q, encoding="utf-8").read() for q in present)

    # ONE pass, stripping the blockquote prefix uniformly. Matching quoted and
    # unquoted blocks with two patterns double-counted every blockquoted
    # example -- once stripped and once with its "> " prefixes intact, which
    # then failed to parse and reported the correct examples as broken. A
    # check that cries wolf on valid input gets switched off.
    blocks = []
    for raw in re.findall(r"```json\n(.*?)```", text, re.S):
        lines = raw.strip().split("\n")
        if all(ln.startswith(">") for ln in lines):
            lines = [ln[2:] if ln.startswith("> ") else ln[1:] for ln in lines]
        blocks.append("\n".join(lines))

    servers = []
    unparseable = []
    for raw in blocks:
        try:
            parsed = _json.loads(raw)
        except ValueError as exc:
            # DO NOT `continue` HERE. The first version of this check skipped
            # anything that failed to parse, which meant a malformed config --
            # the exact defect being guarded against -- made the check pass by
            # being discarded. Caught by planting a broken block and watching
            # nothing fail. A block that mentions mcpServers and does not parse
            # IS the finding.
            if "mcpServers" in raw:
                unparseable.append(str(exc))
            continue
        if isinstance(parsed, dict) and "mcpServers" in parsed:
            servers.append(parsed["mcpServers"])

    check(not unparseable,
          "every block that looks like an mcpServers config parses as JSON",
          "UNPARSEABLE: %s\nAn operator pasting this gets a server that never "
          "starts, and the agent has no error to report." % "; ".join(unparseable))

    check(len(servers) >= 2,
          "found %d pasteable mcpServers example(s)" % len(servers),
          "Expected at least the uvx and python3 variants. If they no longer "
          "parse as JSON, an operator pasting one gets a server that never "
          "starts and an agent with no error to report.")

    for block in servers:
        entry = block.get("stringcup")
        check(isinstance(entry, dict), "an example defines the 'stringcup' server")
        if not isinstance(entry, dict):
            continue
        check(bool(entry.get("command")), "...and names a command to run")
        identity = (entry.get("env") or {}).get("STRINGCUP_IDENTITY", "")
        # INVERTED ON 2026-09-16, AND THE OLD VERSION OF THIS CHECK IS WHY THE
        # DEFECT KEPT COMING BACK.
        #
        # It used to REQUIRE an absolute STRINGCUP_IDENTITY, on the reasoning
        # below -- correct when the default was one $HOME-relative file, and
        # exactly wrong once the default became per-directory. An absolute path
        # is the `explicit` rule: machine-wide, so every session on the box is
        # one agent and two cannot pair. So the suite was ENFORCING the
        # anti-pattern while three separate commits removed it from the prose,
        # which is the cleanest example this project has of the rule it already
        # wrote down: when you change a property, go and find every check that
        # depended on it.
        #
        # The $HOME hazard it was written for is real and did not go away. It
        # is now the `no-cwd-scope` rule, and its remedy is a NAME, not a
        # pinned path -- so the docs point there and this check demands the
        # absence of a pin.
        check(not identity,
              "...and pins no STRINGCUP_IDENTITY (%r)" % identity,
              "An absolute path is machine-wide: every session becomes one "
              "agent and two cannot pair. Set nothing, or use "
              "STRINGCUP_IDENTITY_NAME.")


#: The three version numbers, snapshotted together.
#:
#: Bumping either module fails this, which forces a decision about the
#: distribution version -- the same mechanism as the `__all__` and `FEATURES`
#: snapshots, and for the same reason: a published PyPI version can never be
#: reused, so the discipline cannot be left to memory.
EXPECTED_VERSIONS = {
    "distribution": "3.29.0",
    "library": "3.27.0",
    "mcp": "1.23.0",
}


#: Strings that were renamed or superseded and must not survive on any
#: PUBLISHED surface. Value is what to say instead, shown in the failure.
#:
#: THIS LIST EXISTS BECAUSE THE RENAME CLASS HIT FOUR TIMES IN ONE DAY, always
#: the same way: fixed where it was found, left live somewhere else.
#: `treat_as` was corrected in stringcup_mcp.py and survived in agent.md --
#: in the prompt-injection warning, the copy AGENTS read. `never holds a key`
#: was corrected on the PyPI page and survived on six surfaces. The retracted
#: two-reader claim was corrected in CLAUDE.md and survived on the PyPI page.
#: A grep takes a second and nobody runs it, so it is a test.
RETIRED_PUBLISHED_STRINGS = {
    "treat_as": "sender_trust (the field receive results actually carry)",
    "identity_shared_across_sessions": "identity_rule_shares_machine_wide",
    "5/hour": "30/hour -- the registration cap was raised 2026-09-16",
    "never holds a key": "never holds a PRIVATE key; it serves public ones",
}


def test_no_retired_string_survives_on_a_published_surface():
    step("8e. no renamed field or superseded number survives in published docs")

    names = ["setup.md", "agent.md", "docs.md", "docs.html", "llms.txt",
             "PROTOCOL.md"]
    paths = [os.path.join(HERE, "..", "..", "public", n) for n in names]
    paths += [os.path.join(HERE, "README.md"),
              os.path.join(HERE, "packaging", "PYPI-README.md"),
              os.path.join(HERE, "..", "..", "README.md"),
              os.path.join(HERE, "..", "..", "SECURITY.md"),
              os.path.join(HERE, "..", "..", "app", "Views", "home.php"),
              os.path.join(HERE, "stringcup.py"),
              os.path.join(HERE, "stringcup_mcp.py")]
    present = [q for q in paths if os.path.isfile(q)]

    if not present:
        print("  \033[33m~\033[0m SKIP: no published surfaces present "
              "(repo-only check, not shipped beside the client)")
        return

    for retired, instead in sorted(RETIRED_PUBLISHED_STRINGS.items()):
        guilty = [os.path.basename(q) for q in present
                  if retired in open(q, encoding="utf-8").read()]
        check(not guilty,
              "%r appears in no published surface" % retired,
              "Found in %s. Say %s instead." % (", ".join(guilty), instead))


def test_no_published_config_pins_an_identity_path():
    step("8d. no published config example pins STRINGCUP_IDENTITY")

    # THIS IS THE DEFECT THAT WAS FIXED THREE TIMES AND SURVIVED ANYWAY.
    #
    # An absolute identity path resolves by the `explicit` rule, which is
    # machine-wide: every session on the box becomes ONE AGENT, and two of them
    # cannot pair -- one opens a rendezvous and the other is told it already
    # holds that side. The published advice CAUSED that, and an operator whose
    # machine had it got it from the PyPI page, as did an agent configuring
    # Stringcup for itself.
    #
    # It was removed from the PyPI page, then from setup.md, then from
    # agent.md's refusal block -- the highest-traffic instruction in the
    # system, since it is what every agent recites to every operator -- and a
    # sweep still found SEVEN instances across FOUR surfaces, including two
    # pages neither reviewer had checked. Fix-as-found missed three times on
    # one defect, which is why this is a test and not a note.
    #
    # `STRINGCUP_IDENTITY_NAME` is the supported way to separate agents and is
    # deliberately NOT matched. Prose that names the variable -- telling a
    # reader to unset it -- is fine too; only an ASSIGNMENT is banned.
    names = ["setup.md", "agent.md", "docs.md", "docs.html", "llms.txt"]
    paths = [os.path.join(HERE, "..", "..", "public", n) for n in names]
    paths += [os.path.join(HERE, "README.md"),
              os.path.join(HERE, "packaging", "PYPI-README.md"),
              os.path.join(HERE, "..", "..", "README.md"),
              os.path.join(HERE, "..", "..", "app", "Views", "home.php")]
    present = [q for q in paths if os.path.isfile(q)]

    if not present:
        print("  \033[33m~\033[0m SKIP: no published surfaces present "
              "(repo-only check, not shipped beside the client)")
        return

    assigns = re.compile(r'STRINGCUP_IDENTITY(?!_NAME)\s*(?:"\s*:|=)')
    for q in present:
        text = open(q, encoding="utf-8").read()
        hits = assigns.findall(text)
        check(not hits,
              "%s pins no identity path" % os.path.basename(q),
              "An absolute STRINGCUP_IDENTITY is the `explicit` rule: "
              "machine-wide, so every session becomes one agent and two "
              "cannot pair. Use STRINGCUP_IDENTITY_NAME, or set nothing and "
              "let the per-directory default apply.")


def test_packaging_cannot_drift_from_the_modules():
    step("8c. one distribution, two modules, three versions kept in step")

    import stringcup_mcp as _mcp

    check(stringcup.__dist_version__ == EXPECTED_VERSIONS["distribution"],
          "distribution version matches the snapshot (%s)" % stringcup.__dist_version__,
          "Bumping a module means deciding whether the distribution version "
          "moves. It must, or `pip install -U` cannot fetch the new file.")
    check(stringcup.__version__ == EXPECTED_VERSIONS["library"],
          "library version matches the snapshot (%s)" % stringcup.__version__)
    check(_mcp.__version__ == EXPECTED_VERSIONS["mcp"],
          "mcp version matches the snapshot (%s)" % _mcp.__version__)

    # A distribution behind the library publishes a version nobody can fetch
    # the new code with.
    dist = tuple(int(x) for x in stringcup.__dist_version__.split("."))
    check(dist >= stringcup.version_info,
          "the distribution version is not behind the library",
          "distribution %s < library %s" % (dist, stringcup.version_info))

    pkg = os.path.join(HERE, "packaging")
    if not os.path.isdir(pkg):
        print("  \033[33m~\033[0m SKIP: packaging/ not present (repo-only, not "
              "shipped beside the client)")
        return

    path = os.path.join(pkg, "pyproject.toml")
    check(os.path.isfile(path), "packaging/pyproject.toml exists")
    if not os.path.isfile(path):
        return

    text = open(path, encoding="utf-8").read()

    # ONE distribution carrying BOTH modules is the point: every drift-warning
    # surface in the server exists because a hand install can upgrade one file
    # and forget the other, and shipping them together makes that impossible
    # for pip users. Two distributions would reintroduce exactly that gap.
    check('py-modules = ["stringcup", "stringcup_mcp"]' in text,
          "the distribution ships BOTH modules, so they cannot drift",
          "Splitting them reintroduces the partial-upgrade failure that "
          "whoami's versions_note exists to report.")
    check('name = "stringcup"' in text, "...under one name")
    check('stringcup-mcp = "stringcup_mcp:main"' in text,
          "...with a console script, which is what removes the path footgun")

    check('dynamic = ["version"]' in text, "the version is declared dynamic")
    check('{attr = "stringcup.__dist_version__"}' in text,
          "...read from __dist_version__, not either module's __version__")
    check(not re.search(r'^version = "', text, re.M),
          "...and never hardcoded",
          "A literal here can disagree with the module, and a published "
          "version cannot be taken back.")

    # The ceiling that is right for this host is wrong for everyone on 3.8+.
    check("python_version < '3.8'" in text and "python_version >= '3.8'" in text,
          "the cryptography ceiling is gated on python_version, not absolute",
          "An unconditional <46 caps every user of the package.")

    # NO SECOND COPY. A duplicated module here would drift from the published
    # file -- the failure clients-SHA256SUMS guards for the curl path.
    # NARROWLY the two module names. The first version flagged any .py here and
    # tripped on apply-published-docs.py, which is a tool rather than a copy --
    # a check that fires on legitimate files is one people switch off.
    strays = [f for f in os.listdir(pkg)
              if f in ("stringcup.py", "stringcup_mcp.py")]
    check(not strays,
          "packaging/ holds no copy of either module",
          "Found %s. build.sh stages the canonical files instead, so a release "
          "cannot drift from the published file." % strays)


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
    test_agent_md_publishes_no_bypass_guidance()
    test_agent_md_configs_are_valid_json()
    test_no_published_config_pins_an_identity_path()
    test_no_retired_string_survives_on_a_published_surface()
    test_packaging_cannot_drift_from_the_modules()
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
