#!/usr/bin/env bash
# Build the distribution from the CANONICAL client files.
#
# ONE distribution, TWO top-level modules -- see pyproject.toml for why. No
# copy of either module lives in this directory: they are staged into a temp
# tree and built from there, so a release cannot drift from the file published
# at stringcup.com/clients/. Same property clients-SHA256SUMS gives the curl
# path.
#
#   ./build.sh           build wheel + sdist into dist/
#   ./build.sh --check   build, install into a clean venv, exercise it
#
# It does NOT upload. A PyPI version can never be reused, so publishing is a
# separate, deliberate, human step -- and not from this host: twine needs
# urllib3, urllib3 v2 needs OpenSSL 1.1.1+, and Amazon Linux 2 ships 1.0.2k.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/.."
OUT="$HERE/dist"
PY="${PYBUILD:-python3}"
CHECK="${1:-}"

ver() { "$PY" -c "import re;print(re.search(r'$2 = \"([^\"]+)\"', open('$SRC/$1').read()).group(1))"; }
DIST_VER=$(ver stringcup.py __dist_version__)
LIB_VER=$(ver stringcup.py __version__)
MCP_VER=$(ver stringcup_mcp.py __version__)

echo "distribution $DIST_VER  (library $LIB_VER, mcp $MCP_VER, shipped together)"

# A distribution version behind either module would publish a version nobody
# can fetch the new code with. Cheap to check, unrecoverable to get wrong.
"$PY" - <<PYGUARD
import sys
d = tuple(int(x) for x in "$DIST_VER".split("."))
for name, v in (("library", "$LIB_VER"), ("mcp", "$MCP_VER")):
    pass
if d < tuple(int(x) for x in "$LIB_VER".split(".")):
    sys.exit("distribution %s is BEHIND the library %s" % ("$DIST_VER", "$LIB_VER"))
PYGUARD

rm -rf "$OUT" "$HERE/.stage"
mkdir -p "$OUT" "$HERE/.stage"
cp "$SRC/stringcup.py" "$SRC/stringcup_mcp.py" "$SRC/README.md" "$HERE/.stage/"
# Apache-2.0 section 4: the licence text ships WITH the distribution. The
# metadata field labels it; this includes it.
cp "$SRC/../../LICENSE" "$SRC/../../NOTICE" "$HERE/.stage/"
cp "$HERE/pyproject.toml" "$HERE/.stage/"
(cd "$HERE/.stage" && "$PY" -m build --outdir "$OUT" >/dev/null)
rm -rf "$HERE/.stage"
ls -1 "$OUT"

if [ "$CHECK" = "--check" ]; then
  echo
  echo "=== installing into a clean venv and exercising it ==="
  VENV="$HERE/.venv-check"
  rm -rf "$VENV"
  "$PY" -m venv "$VENV"
  "$VENV/bin/pip" -q install --upgrade pip >/dev/null
  "$VENV/bin/pip" -q install "$OUT/stringcup-$DIST_VER-py3-none-any.whl"

  "$VENV/bin/python" - <<'PYCHECK'
import stringcup, stringcup_mcp
print("  distribution          ->", stringcup.__dist_version__)
print("  import stringcup      ->", stringcup.__version__)
print("  import stringcup_mcp  ->", stringcup_mcp.__version__)
print("  tools                 ->", len(stringcup_mcp.TOOLS))
# THE POINT OF ONE DISTRIBUTION: these cannot drift, so assert it holds in the
# artifact rather than trusting that it does.
assert stringcup_mcp.BUILT_AGAINST <= stringcup.version_info, "BUILT_AGAINST drifted"
assert stringcup_mcp.__version__ in stringcup_mcp.INSTRUCTIONS, "build marker missing"
note = stringcup_mcp._version_note()
assert note is None, "a single distribution must never report a version mismatch: %r" % note
print("  versions_note         -> None (cannot drift in one distribution)")
PYCHECK

  test -x "$VENV/bin/stringcup-mcp" || { echo "  MISSING console script"; exit 1; }
  echo "  console script        -> $VENV/bin/stringcup-mcp"

  # Speak actual JSON-RPC: an entry point that imports but does not serve is
  # the failure this exists to catch.
  printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}' \
    | timeout 10 "$VENV/bin/stringcup-mcp" 2>/dev/null \
    | "$PY" -c "import json,sys; d=json.loads(sys.stdin.readline()); print('  initialize            ->', d['result']['serverInfo'])"
  rm -rf "$VENV"

  if [ -n "${TWINE:-}" ]; then
    echo
    $TWINE check "$OUT"/* || { echo "  twine check FAILED"; exit 1; }
  else
    echo
    echo "  (set TWINE='/path/to/python -m twine' to validate metadata; skipped)"
  fi
fi
