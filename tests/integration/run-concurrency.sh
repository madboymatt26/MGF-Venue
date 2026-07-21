#!/bin/sh
set -eu

compose='docker compose -f tests/integration/docker-compose.yml'

run_race() {
  invoice_ref="$1"
  mode="$2"
  suffix="$3"
  order_a="$4"
  order_b="$5"
  $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/seed-reservation.php "$invoice_ref" "$mode" --allow-root
  log_a="tests/integration/.worker-${suffix}-a"
  log_b="tests/integration/.worker-${suffix}-b"
  exit_a="tests/integration/.exit-${suffix}-a"
  exit_b="tests/integration/.exit-${suffix}-b"
  rm -f "$log_a" "$log_b" "$exit_a" "$exit_b"
  ( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-worker.php "$invoice_ref" "$order_a" "$mode" --allow-root >"$log_a" 2>&1; echo "$?" >"$exit_a" ) &
  pid_a=$!
  ( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-worker.php "$invoice_ref" "$order_b" "$mode" --allow-root >"$log_b" 2>&1; echo "$?" >"$exit_b" ) &
  pid_b=$!
  wait "$pid_a"
  wait "$pid_b"
  code_a=$(cat "$exit_a")
  code_b=$(cat "$exit_b")
  if { [ "$code_a" -eq 0 ] && [ "$code_b" -eq 0 ]; } || { [ "$code_a" -ne 0 ] && [ "$code_b" -ne 0 ]; }; then
    echo "Expected exactly one successful worker; got A=$code_a B=$code_b" >&2
    cat "$log_a" "$log_b" >&2
    return 1
  fi
  $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/assert-reservation.php "$invoice_ref" "$order_a" "$order_b" --allow-root
  echo "OK: synchronised ${mode:-different-session} race produced one winner and one loser."
}

if $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/audit-assertions-self-test.php fail --allow-root; then
  echo "Controlled false assertion unexpectedly returned success." >&2
  exit 1
fi
echo "OK: controlled false adversarial assertion returned a non-zero WP-CLI exit."
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/audit-assertions-self-test.php pass --allow-root

audit_failed=0
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/audit-regressions.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/audit-migration-regressions.php --allow-root || audit_failed=1
run_race INT-RES-DIFFERENT different different 1001 1002 || audit_failed=1
run_race INT-RES-SAME shared same 2001 2002 || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-state-machine.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/woocommerce-callbacks.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/financial-flows.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/legacy-adoption.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/mutation-matrix.php --allow-root
sh tests/integration/run-migrations.sh
sh tests/integration/run-catch-up.sh
if [ "$audit_failed" -ne 0 ]; then
  echo "One or more adversarial regression scenarios failed." >&2
  exit 1
fi
