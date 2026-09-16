# Initial publish — checklist

Everything below is verified as of this commit except the two items marked
**unverifiable here**, which say so rather than implying coverage.

## Ready

| | |
|---|---|
| Names claimed | **`stringcup` 3.23.0** and **`stringcup-mcp` 1.0.0** (a no-code placeholder depending on `stringcup`) are both published. Neither name is free any more; check with the JSON API, never the project page |
| Artifacts build | `stringcup-3.22.0-py3-none-any.whl`, `stringcup-3.22.0.tar.gz` |
| Metadata valid | `twine check` **PASSED** on both |
| Versions derived | `version = {attr = "stringcup.__dist_version__"}` — no literal anywhere |
| Installs clean | fresh venv, `import stringcup` 3.22.0, `import stringcup_mcp` 1.18.0, 15 tools |
| Console script | `stringcup-mcp` on `$PATH`, answers a real `initialize` over stdio |
| Cannot drift | `_version_note()` is `None` in the installed artifact, asserted by `build.sh --check` |
| Dependency gated | `cryptography<46` only below Python 3.8, so 3.12 users are not capped |
| Legal | `LICENSE` **and** `NOTICE` in both artifacts — Apache-2.0 §4 and §4(d) |
| No stray copies | `packaging/` holds no module; `build.sh` stages the canonical files |
| Enforced | `test_contract.py` snapshots all three versions and the packaging shape; each check verified non-inert |

## Closed, 2026-09-16 — both former "unverifiable" items

- **Python 3.8+.** Built and exercised on macOS arm64 / CPython 3.12.14.
- **`uvx --from stringcup stringcup-mcp`.** Run against the published index
  (`uvx --refresh`), answering a real JSON-RPC `initialize`.

**Check name availability with the JSON API, never the project page.**
`https://pypi.org/pypi/<name>/json` returns 404 for a free name.
`https://pypi.org/project/<name>/` returns **200 behind a bot challenge** for a
name that does not exist, so a `curl -w %{http_code}` check on it reads as
TAKEN and nearly blocked this release.

## The invariant is TAG == artifact, not main == artifact

Asked directly, after a docstring fix left `stringcup_mcp.py` on `main`
differing from the published 3.23.0 wheel: should shipped content changing
force a version bump, enforced in `test_contract.py`?

**No, and the invariant it would defend is the wrong one.** `main` moving ahead
of the last release is what `main` is for; requiring `main == artifact` means a
version for every typo, which is the churn this project has twice decided
against. The property worth trusting is that **the tag reproduces the
artifact** — `dist-v3.23.0` → `22d424a`, whose two modules are byte-identical
to a fresh `pip download` of 3.23.0. A reader who wants the shipped source
checks out the tag, not `main`.

The analogy to the unbumped-2.3.0 incident does not carry, and the difference
is the useful part: that was a **contract** — `__all__`, MCP result keys —
which `require_version()` promised and which a caller could depend on
programmatically. A docstring is not something any caller can depend on, which
is exactly why `test_contract.py` cannot and should not police it. It is also
a **no-network** suite by design, so it cannot know what PyPI serves.

What *is* worth guarding is the unrecoverable mistake — spending a version
number twice — so `build.sh` now checks the index and **warns**. It must never
fail on this: rebuilding an already-published version is how you prove the
tagged tree still reproduces the artifact, so a guard that refused would block
the check that makes the tag trustworthy in the first place.

## Provenance of the 3.22.0 upload

Recorded because it cannot be reconstructed and no tag can honestly stand in
for it.

| | |
|---|---|
| Source commit | `a4d67c6475085233d8df2a9a4e621f35e3e1796e` (confirmed against the GitHub API) |
| Plus | three **uncommitted** local edits: `packaging/PYPI-README.md` (new), `pyproject.toml` (`readme`), `build.sh` (probe + staging) |
| Toolchain | macOS arm64, CPython 3.12.14, setuptools 84.0.0, wheel 0.48.0 |
| Metadata | 2.4, licences under `dist-info/licenses/` |
| Uploaded | 2026-09-16 04:51:49Z |

**The shipped tree was never a commit, so `dist-v3.22.0` is deliberately not
tagged.** Tagging a reconstruction would assert an identity nobody verified.
3.23.0 is the first release built from a committed tree, and it carries the tag.

**The build host changes the artifact.** This relay has setuptools 49.1.3 and
produces `Metadata-Version: 2.1` with `dist-info/LICENSE`; the publishing Mac
has 84.0.0 and produces 2.4 with `dist-info/licenses/`. `requires =
["setuptools>=61"]` admits both. Record the builder version for every upload,
and do not carry a `twine check` result from one host to an artifact built on
another — that is verifying something other than what ships.

## Publish

**Build and upload from the same machine, and not this one.** `twine` needs
`urllib3`, v2 needs OpenSSL 1.1.1+, and Amazon Linux 2 ships 1.0.2k. A fresh
clone at a tag beats building here for a second reason: this working tree is
what nginx serves live, and step 4 below rewrites it.

**On macOS, set `PYBUILD` first.** `build.sh` defaults to `python3`, which on a
Mac without a real Python is the Xcode stub — it dies on the `xcode-select` nag
before building anything. The person running this is the most likely to be on a
Mac, so:

```bash
PYBUILD=/opt/homebrew/bin/python3 ./build.sh --check   # or any real interpreter
```

Reported by the agent that published 3.24.0, which hit exactly this.

```bash
# 1. build from a clean checkout at the release tag
cd clients/python/packaging && ./build.sh --check

# 2. upload
python3 -m twine upload dist/*

# 3. confirm it resolves from a clean environment
pip download stringcup --no-deps -d /tmp/verify
uvx --from stringcup stringcup-mcp   # should wait on stdin, not error
```

## Then, and only then, switch the docs

```bash
cd clients/python/packaging
python3 apply-published-docs.py --check     # must print "ready to apply: yes"
python3 apply-published-docs.py
cd ../../.. && php spark clients:checksums && tests/run_all.sh
```

**The docs are deliberately not updated yet.** The relay serves from the
working tree, so writing `pip install stringcup` before the package exists
would publish an instruction that 404s to every reader — the same failure as
citing a test file that is not served. The script refuses to run until PyPI
reports a version matching `__dist_version__`, is idempotent, and aborts if any
anchor has drifted rather than corrupting a page with a blind replace.

## What it changes

`setup.md` (PASTE 1 becomes one `claude mcp add` command), the client README and
root README (package first, single file still offered), `docs.md`, `llms.txt`,
and `IndexController` (a `pypi` link in the self-describing index). The `curl`
path is kept everywhere — one auditable file is a feature, and the package ships
the identical file.
