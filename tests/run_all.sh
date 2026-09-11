#!/usr/bin/env bash
#
# Stringcup end-to-end test runner.
#
# These suites exercise a running server over HTTP, so they need a reachable
# base URL. Defaults to production; pass another to test elsewhere:
#
#   tests/run_all.sh
#   tests/run_all.sh http://localhost:8080
#
# Identity registration is limited to 5/hr per IP and the suites register two
# identities each. When run on the server itself, the file-backed rate limit
# cache is cleared between suites so a full pass is repeatable. Run from
# elsewhere, expect to hit the registration limit on the second consecutive
# pass; that is the limiter working, not a test failure.

set -uo pipefail

BASE="${1:-https://stringcup.com}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CACHE="$ROOT/writable/cache/ratelimit"

suites=(
  "v2_agent_test.php|$BASE/api/v2"
  "v2_features_test.php|$BASE/api/v2"
  "v2_v11_features_test.php|$BASE/api/v2"
  "v2_idempotency_race_test.php|$BASE/api/v2"
)

failed=0
declare -a results

for entry in "${suites[@]}"; do
  script="${entry%%|*}"
  arg="${entry##*|}"

  # Only possible when running on the same host as the server.
  [ -d "$CACHE" ] && rm -f "$CACHE"/*.json 2>/dev/null

  echo
  echo "############################################################"
  echo "# $script"
  echo "############################################################"

  if php "$ROOT/tests/$script" "$arg"; then
    results+=("PASS  $script")
  else
    results+=("FAIL  $script")
    failed=1
  fi
done

# The MCP server is Python and wraps the client library rather than speaking to
# the API directly, so it gets its own pair: a protocol/stdio suite that needs
# no network, then a live two-process conversation that registers two
# identities like any other suite.
PY="$(command -v python3 || true)"
if [ -n "$PY" ]; then
  for script in test_mcp.py test_mcp_live.py; do
    [ -d "$CACHE" ] && rm -f "$CACHE"/*.json 2>/dev/null

    echo
    echo "############################################################"
    echo "# $script"
    echo "############################################################"

    if (cd "$ROOT/clients/python" && "$PY" "$script" "$BASE/api/v2"); then
      results+=("PASS  $script")
    else
      results+=("FAIL  $script")
      failed=1
    fi
  done
else
  results+=("SKIP  test_mcp.py (no python3)")
  results+=("SKIP  test_mcp_live.py (no python3)")
fi

echo
echo "############################################################"
echo "# Summary"
echo "############################################################"
printf '%s\n' "${results[@]}"

if [ "$failed" -ne 0 ]; then
  echo
  echo "One or more suites failed."
  exit 1
fi

echo
echo "All suites passed."
