# Packaging

**One distribution, two top-level modules.** `pip install stringcup` gives you
`stringcup.py`, `stringcup_mcp.py` and a `stringcup-mcp` console script.

## Why one and not two

The first attempt split them, reasoning that two files with independent
versions want two packages. That was wrong, and the argument against it is
stronger than the argument for it:

**Every drift-detection surface in the MCP server exists because the two files
can be upgraded separately.** `whoami` returns `library_version`,
`mcp_version`, `versions_note` and `tool_list_check`; the server warns at
startup against `BUILT_AGAINST`; `CLAUDE.md` records the reason as *"a partial
upgrade is one forgotten line."* An agent reported that exact failure and could
not diagnose it.

**Shipping both in one distribution makes that drift structurally impossible
for anyone installing with pip.** `build.sh --check` asserts it on the built
artifact: `_version_note()` returns `None`, and it cannot return anything else.
That is worth more than one version number per file.

It also means one name to claim, one upload, no publish ordering, and no window
where the server installs and the library does not resolve.

The `curl` path still has two files and still needs every one of those
warnings. Nothing about it changes.

## The third version number is not redundant

`stringcup.__dist_version__` is neither module's version, and a distribution
carries exactly one. If it tracked the library, an MCP-only change would not
bump it and `pip install -U` would never fetch the new server; if it tracked
the server, the reverse. So it is its own number, it must increase whenever
either module's does, and `test_contract.py` snapshots all three so bumping a
module forces a decision about it.

It is **deliberately not in `__all__`** — build metadata, not client API.
`pyproject.toml` reads it via `[tool.setuptools.dynamic] attr`, which needs no
export. The contract test rejected the first attempt at exporting it, which is
the discipline working.

## The single source of truth stays `clients/python/*.py`

There is **no copy** of either module in this directory. `build.sh` stages the
canonical files into a temporary tree and builds from there, so a packaged
release cannot drift from the published file — the same property
`clients-SHA256SUMS` gives the `curl` path.

## The dependency pin is conditional, and that matters

`requirements.txt` pins `cryptography>=3.4,<46` because **this host runs Python
3.7** (the newest Amazon Linux 2 offers) and 46 drops 3.7. **Publishing that
ceiling unchanged would cap every user of the package**, including everyone on
3.12 who has no reason to be held at cryptography 45. An environment marker
keeps the pin where it applies and nowhere else.

## Publishing does not undo

A PyPI version can never be reused. Deleting a release does not free the
number, and anyone who installed it may have it cached. Build, install into a
clean venv and exercise it before uploading — `build.sh --check` does that.

## What cannot be verified from this host

This box has **only Python 3.7**. `requires-python = ">=3.7"` is therefore an
untested claim above 3.7: the wheels are pure-Python and there is no reason it
would fail, but nobody has run it on 3.12. Say so rather than implying
coverage. The same caveat applies to `uvx`, which is not installed here.

## Uploading

**Not from this host.** `twine` needs `urllib3`, and `urllib3` v2 requires
OpenSSL 1.1.1+ while Amazon Linux 2 ships 1.0.2k — so a plain
`pip install twine` here fails on *import*. Pinning `urllib3<2` makes
`twine check` work (the metadata validates), but uploading over TLS from this
box is not a path worth depending on.

Build here, upload from a machine with a current OpenSSL:

```bash
./build.sh --check                    # on the relay, or anywhere
# then, on your own machine:
python3 -m twine upload dist/*
```

**Publish `stringcup` first and confirm `pip install stringcup` works before
uploading `stringcup-mcp`**, which depends on it. If the dependency is not
resolvable yet, an installer of the server gets a failure that looks like a
broken package rather than a missing one.

### There is no way to reserve a name

PyPI has no reservation mechanism — a name is claimed by uploading a
distribution to it, and nothing else holds it. Note that PyPI normalizes
names, so `stringcup-mcp` and `stringcup_mcp` are the same project and
claiming one claims both; `string-cup` is a *different* name and is not
covered.
