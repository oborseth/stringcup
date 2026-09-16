# `stringcup-mcp` 1.0.0 — published 2026-09-16, and it should never need a second release

Live at <https://pypi.org/project/stringcup-mcp/>, uploaded 2026-09-16T16:30:17Z.

Verified against the **published** artifact, not the local build:

| Checked | Result |
|---|---|
| Modules shipped | **none** — no `.py` in the wheel, `top_level.txt` is empty |
| Dependency | `stringcup>=3.23.0` — a **floor, not a pin** |
| Legal | `LICENSE` and `NOTICE` both in `dist-info/licenses/` |
| End to end | `pip install stringcup-mcp` in a clean venv pulls `stringcup 3.23.0` and puts a working `stringcup-mcp` on `PATH`; it answers a real JSON-RPC `initialize` |

**A floor is what makes this a one-time publish.** Every future `stringcup`
release satisfies `>=3.23.0`, so the stub never goes stale and never needs
republishing. A pin would have turned a defensive name claim into a permanent
release obligation — and a forgotten one would hold installers at 3.23.0
forever.

**Do not add modules to it.** The whole safety property is that there is no
second copy of anything to drift. If this ever ships code, it becomes exactly
the split distribution that `packaging/pyproject.toml` argues against.

## The committed source reproduces the published wheel

Rebuilt here from these files and diffed against a fresh `pip download` of
1.0.0. The wheel ships no modules either way and every metadata *value*
matches. The only deltas are the build host's renderer — `Metadata-Version`
2.1 vs 2.4, `Requires-Dist: stringcup >=3.23.0` vs `stringcup>=3.23.0`, and a
`Dynamic: license-file` line — which is the setuptools 49.1.3 (this relay) vs
84.0.0 (the publishing Mac) divergence already recorded in `PUBLISH.md`.
Content reproduces; byte-for-byte needs the same setuptools.

## Rebuilding it, if that is ever needed

`license-files` resolves relative to the pyproject directory, so `LICENSE` and
`NOTICE` must be staged beside this file the way `build.sh` stages them for the
real distribution — they are not picked up from the repo root.
