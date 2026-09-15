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
# Config consistency first, because it needs no server and no network.
#
# filters:check asserts every rate-limit bucket that AuthFilter does not
# protect keys on IP rather than on a caller-supplied bearer string. Those are
# two hand-maintained lists in different files that must agree, and they
# disagreed -- api/v2/identities_put was missing, making its 30/hour limit
# unenforceable by anyone presenting a fresh random token.
echo
echo "############################################################"
echo "# filters:check"
echo "############################################################"
if (cd "$ROOT" && php spark filters:check); then
  results+=("PASS  filters:check")
else
  results+=("FAIL  filters:check")
  failed=1
fi

# limits:check asserts every limit the server ENFORCES is also PUBLISHED by
# the surfaces clients plan against. The per-sender quota shipped without
# touching StatsController, so the dashboard endpoint advertised 2000 and 64
# MiB while senders were refused at 200 and 16 MiB -- figures no client could
# ever reach, on an endpoint whose documented purpose is capacity planning.
echo
echo "############################################################"
echo "# limits:check"
echo "############################################################"
if (cd "$ROOT" && php spark limits:check); then
  results+=("PASS  limits:check")
else
  results+=("FAIL  limits:check")
  failed=1
fi

# no network, then a live two-process conversation that registers two
# identities like any other suite.
PY="$(command -v python3 || true)"
if [ -n "$PY" ]; then
  # test_contract.py needs no network: it asserts the version and public
  # surface invariants that stop a changed contract shipping under an
  # unchanged version number.
  # test_features_v11.py is in this list because it was NOT, and rotted
  # unnoticed: it asserted a transcript key renamed in 2.4.0 and registered
  # six identities against a 5/hour bucket, so it had been unrunnable for
  # weeks while nothing reported a thing. A suite nobody runs is not coverage.
  # test_properties.py asserts the PROMISES in PROTOCOL.md B.6 by observing a
  # real run, not by reading the code. Every other suite here is written from
  # the implementation and can only confirm it -- which is how the rotation
  # defect passed a suite that asserted the very behaviour causing the bug.
  for script in test_contract.py test_mcp.py test_features_v11.py test_properties.py test_mcp_live.py; do
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
  results+=("SKIP  test_features_v11.py (no python3)")
  results+=("SKIP  test_properties.py (no python3)")
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
