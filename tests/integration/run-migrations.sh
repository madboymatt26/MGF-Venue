#!/bin/sh
set -eu
compose='docker compose -f tests/integration/docker-compose.yml'
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/migration-scenarios.php --allow-root
rm -f tests/integration/.worker-migration-* tests/integration/.exit-migration-*
( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/migration-worker.php holder --allow-root >tests/integration/.worker-migration-holder 2>&1; echo "$?" >tests/integration/.exit-migration-holder ) &
holder_pid=$!
( set +e; $compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/migration-worker.php contender --allow-root >tests/integration/.worker-migration-contender 2>&1; echo "$?" >tests/integration/.exit-migration-contender ) &
contender_pid=$!
wait "$holder_pid"
wait "$contender_pid"
if [ "$(cat tests/integration/.exit-migration-holder)" -ne 0 ] || [ "$(cat tests/integration/.exit-migration-contender)" -ne 0 ]; then
  cat tests/integration/.worker-migration-holder tests/integration/.worker-migration-contender >&2
  exit 1
fi
cat tests/integration/.worker-migration-holder tests/integration/.worker-migration-contender
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/migration-worker.php abandoned-owner --allow-root
$compose run --rm -T cli wp eval-file /workspace/tests/integration/scenarios/migration-worker.php recover --allow-root
