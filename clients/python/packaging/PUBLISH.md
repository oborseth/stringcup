# Initial publish — checklist

Everything below is verified as of this commit except the two items marked
**unverifiable here**, which say so rather than implying coverage.

## Ready

| | |
|---|---|
| Name available | `stringcup` 404s on PyPI; `stringcup_mcp` normalizes to `stringcup-mcp`, also free |
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

## Unverifiable from this host

- **Python 3.8+.** This box has only 3.7. `requires-python = ">=3.7"` is a
  floor, the wheels are pure-Python and the code is 3.7-targeted syntax, so
  there is no mechanism by which 3.12 would fail — but nobody has run it.
- **`uvx --from stringcup stringcup-mcp`.** `uv` is not installed here. The
  entry point is verified; the `uvx` invocation is reasoned from it.

Both are worth a two-minute check on your machine after publishing.

## Publish

Not from the relay: `twine` needs `urllib3`, v2 needs OpenSSL 1.1.1+, and
Amazon Linux 2 ships 1.0.2k. Build here, upload from your machine.

```bash
# 1. build on the relay
cd clients/python/packaging && ./build.sh --check

# 2. copy dist/ to your machine, then
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
