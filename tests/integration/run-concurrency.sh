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

run_guard_mutation_control() {
  invoice_ref='INT-RES-GUARD-CONTROL'
  $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/seed-reservation.php "$invoice_ref" different --allow-root
  $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-guard-toggle.php disable "$invoice_ref" --allow-root
  ( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-unsafe-worker.php "$invoice_ref" 9001 --allow-root >tests/integration/.worker-guard-a 2>&1; echo "$?" >tests/integration/.exit-guard-a ) &
  pid_a=$!
  ( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-unsafe-worker.php "$invoice_ref" 9002 --allow-root >tests/integration/.worker-guard-b 2>&1; echo "$?" >tests/integration/.exit-guard-b ) &
  pid_b=$!
  wait "$pid_a"; wait "$pid_b"
  code_a=$(cat tests/integration/.exit-guard-a); code_b=$(cat tests/integration/.exit-guard-b)
  detected=0
  if [ "$code_a" -eq 0 ] && [ "$code_b" -eq 0 ]; then
    if ! $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/assert-reservation.php "$invoice_ref" 9001 9002 --allow-root; then detected=1; fi
  fi
  $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-guard-toggle.php restore "$invoice_ref" --allow-root
  if [ "$detected" -ne 1 ]; then echo "Concurrency mutation control did not detect bypassed CAS/uniqueness protection (A=$code_a B=$code_b)." >&2; return 1; fi
  echo "OK: guard-removal mutation produced two winners and the concurrency assertion failed closed."
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
for iteration in 1 2 3; do
  run_race "INT-RES-DIFFERENT-${iteration}" different "different-${iteration}" "$((1000+iteration*10+1))" "$((1000+iteration*10+2))" || audit_failed=1
  run_race "INT-RES-SAME-${iteration}" shared "same-${iteration}" "$((2000+iteration*10+1))" "$((2000+iteration*10+2))" || audit_failed=1
done
run_guard_mutation_control || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-state-machine.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/woocommerce-callbacks.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/financial-flows.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/invoice-document-flows.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/invoice-document-extended.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/invoice-supplement-lifecycle.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/invoice-transaction-failures.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/invoice-money-and-delivery.php --allow-root || audit_failed=1
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/legacy-adoption.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/mutation-matrix.php --allow-root
sh tests/integration/run-migrations.sh
sh tests/integration/run-catch-up.sh
if [ "$audit_failed" -ne 0 ]; then
  echo "One or more adversarial regression scenarios failed." >&2
  exit 1
fi
