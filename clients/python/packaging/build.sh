#!/usr/bin/env bash
# Build both distributions from the CANONICAL client files.
#
# No second copy of either module lives in this directory: the files are
# staged into a temp tree and built from there, so a packaged release cannot
# drift from the file published at stringcup.com/clients/. That is the same
# property clients-SHA256SUMS gives the curl path.
#
#   ./build.sh          build wheels + sdists into dist/
#   ./build.sh --check   build, then install into a clean venv and exercise it
#
# It does NOT upload. Publishing is irreversible -- a PyPI version can never be
# reused -- so it is a separate, deliberate, human step.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/.."
OUT="$HERE/dist"
CHECK="${1:-}"

LIB_VER=$(python3 -c "import re;print(re.search(r'__version__ = \"([^\"]+)\"', open('$SRC/stringcup.py').read()).group(1))")
MCP_VER=$(python3 -c "import re;print(re.search(r'__version__ = \"([^\"]+)\"', open('$SRC/stringcup_mcp.py').read()).group(1))")
FLOOR=$(python3 -c "import re;t=re.search(r'BUILT_AGAINST = \((\d+), (\d+), (\d+)\)', open('$SRC/stringcup_mcp.py').read());print('.'.join(t.groups()))")

echo "library $LIB_VER | mcp $MCP_VER | mcp requires stringcup>=$FLOOR"

rm -rf "$OUT" "$HERE/.stage"
mkdir -p "$OUT"

build_one() {
  local name="$1" module="$2" toml="$3"
  local stage="$HERE/.stage/$name"
  mkdir -p "$stage"
  cp "$SRC/$module" "$stage/"
  cp "$SRC/README.md" "$stage/README.md"
  sed "s/BUILT_AGAINST_FLOOR/$FLOOR/" "$HERE/$toml" > "$stage/pyproject.toml"
  (cd "$stage" && "${PYBUILD:-python3}" -m build --outdir "$OUT" >/dev/null)
  echo "  built $name"
}

build_one stringcup      stringcup.py     pyproject.stringcup.toml
build_one stringcup-mcp  stringcup_mcp.py pyproject.stringcup-mcp.toml

ls -1 "$OUT"

if [ "$CHECK" = "--check" ]; then
  echo
  echo "=== installing into a clean venv and exercising it ==="
  VENV="$HERE/.venv-check"
  rm -rf "$VENV"
  "${PYBUILD:-python3}" -m venv "$VENV"
  "$VENV/bin/pip" -q install --upgrade pip >/dev/null
  "$VENV/bin/pip" -q install "$OUT"/stringcup-"$LIB_VER"-py3-none-any.whl \
                             "$OUT"/stringcup_mcp-"$MCP_VER"-py3-none-any.whl

  "$VENV/bin/python" - <<'PYCHECK'
import stringcup, stringcup_mcp, sys
print("  import stringcup      ->", stringcup.__version__)
print("  import stringcup_mcp  ->", stringcup_mcp.__version__)
assert stringcup_mcp.BUILT_AGAINST <= stringcup.version_info, "BUILT_AGAINST drifted"
assert stringcup_mcp.__version__ in stringcup_mcp.INSTRUCTIONS, "build marker missing"
print("  tools                 ->", len(stringcup_mcp.TOOLS))
PYCHECK

  echo "  console script        -> $VENV/bin/stringcup-mcp"; test -x "$VENV/bin/stringcup-mcp" || { echo "  MISSING console script"; exit 1; }

  # Speak actual JSON-RPC to the installed console script: an entry point that
  # imports but does not serve is the failure this check exists for.
  printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}' \
    | timeout 10 "$VENV/bin/stringcup-mcp" 2>/dev/null \
    | python3 -c "import json,sys; d=json.loads(sys.stdin.readline()); print('  initialize ->', d['result']['serverInfo'])"
  rm -rf "$VENV"
fi

rm -rf "$HERE/.stage"
