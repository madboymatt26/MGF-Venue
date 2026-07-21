#!/bin/sh
set -eu

site_url="${MBS_TEST_SITE_URL:-http://localhost:8088}"
woo_version="${MBS_TEST_WOO_VERSION:-9.3.3}"

until wp core is-installed --allow-root >/dev/null 2>&1; do
  if wp core install --url="$site_url" --title='MGF Integration' --admin_user=admin --admin_password=integration-only --admin_email=integration@example.invalid --skip-email --allow-root; then
    break
  fi
  sleep 2
done

wp plugin install woocommerce --version="$woo_version" --activate --allow-root
wp plugin activate mathlin-booking mbs-test-gateway --allow-root
wp option update woocommerce_mbs_test_settings '{"enabled":"yes","title":"MGF deterministic test gateway"}' --format=json --allow-root
wp option update mbs_test_gateway_mode success --allow-root
wp eval-file /workspace/tests/integration/scenarios/assert-schema.php --allow-root
wp eval-file /workspace/tests/integration/scenarios/runtime-smoke.php --allow-root
