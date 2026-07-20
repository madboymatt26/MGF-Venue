#!/bin/sh
set -eu

compose='docker compose -f tests/integration/docker-compose.yml'
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/seed-reservation.php --allow-root

rm -f tests/integration/.worker-a tests/integration/.worker-b
($compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-worker.php INT-RES-1 1001 --allow-root >tests/integration/.worker-a 2>&1; echo $? >>tests/integration/.worker-a) &
pid_a=$!
($compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/reservation-worker.php INT-RES-1 1002 --allow-root >tests/integration/.worker-b 2>&1; echo $? >>tests/integration/.worker-b) &
pid_b=$!
wait "$pid_a" || true
wait "$pid_b" || true

$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/assert-reservation.php INT-RES-1 --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/woocommerce-callbacks.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/legacy-adoption.php --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/mutation-matrix.php --allow-root
rm -f tests/integration/.worker-a tests/integration/.worker-b
