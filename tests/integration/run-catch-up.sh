#!/bin/sh
set -eu
compose='docker compose -f tests/integration/docker-compose.yml'
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/catch-up-scenarios.php --allow-root
rm -f tests/integration/.worker-catchup-* tests/integration/.exit-catchup-*
( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/catch-up-worker.php A --allow-root >tests/integration/.worker-catchup-a 2>&1; echo "$?" >tests/integration/.exit-catchup-a ) &
pid_a=$!
( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/catch-up-worker.php B --allow-root >tests/integration/.worker-catchup-b 2>&1; echo "$?" >tests/integration/.exit-catchup-b ) &
pid_b=$!
wait "$pid_a"
wait "$pid_b"
if [ "$(cat tests/integration/.exit-catchup-a)" -ne 0 ] || [ "$(cat tests/integration/.exit-catchup-b)" -ne 0 ]; then
  cat tests/integration/.worker-catchup-a tests/integration/.worker-catchup-b >&2
  exit 1
fi
cat tests/integration/.worker-catchup-a tests/integration/.worker-catchup-b
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/assert-catch-up.php --allow-root
