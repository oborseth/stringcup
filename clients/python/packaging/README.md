# Packaging

**Two packages, not one, and the reason is the versioning.**

`stringcup.py` and `stringcup_mcp.py` version *independently* — 3.22.0 and
1.18.0 as of writing — and `BUILT_AGAINST` ties a server build to a library
version. A single PyPI package can carry only one version number, so:

- If the package version tracked the **library**, an MCP-only change would not
  bump it and `pip install -U` would never fetch the new server. That defeats
  the point of packaging.
- If it were a **third** number, there would be three versions to keep in step
  instead of two, and this project's `test_contract.py` exists precisely
  because that discipline has already failed once.

So: `stringcup` carries the library version, `stringcup-mcp` carries the server
version and depends on `stringcup`. That maps one-to-one onto the two files and
keeps `require_version()` and `BUILT_AGAINST` meaning exactly what they mean
today.

## The single source of truth stays `clients/python/*.py`

There is **no second copy** of either module in this directory. `build.sh`
stages the canonical files into a temporary tree and builds from there, so a
packaged release cannot drift from the published file — which is the same
failure `clients-SHA256SUMS` guards against for the `curl` path.

## The dependency pin is conditional, and that matters

`requirements.txt` pins `cryptography>=3.4,<46` because **this host runs Python
3.7** (the newest Amazon Linux 2 offers) and 46 drops 3.7. **Publishing that
ceiling unchanged would cap every user of the package**, including everyone on
3.12 who has no reason to be held at cryptography 45. The packages therefore
use an environment marker: the ceiling applies only below 3.8.

## Publishing does not undo

A PyPI version can never be reused. Deleting a release does not free the
number, and anyone who installed it may have it cached. Build, install into a
clean venv and exercise it before uploading — `build.sh --check` does that.

## What cannot be verified from this host

This box has **only Python 3.7**. `requires-python = ">=3.7"` is therefore an
untested claim above 3.7: the wheels are pure-Python and there is no reason it
would fail, but nobody has run it on 3.12. Say so rather than implying
coverage. The same caveat applies to `uvx`, which is not installed here.
