# WordPress/WooCommerce/MariaDB integration harness

This isolated harness uses MariaDB 10.11, WordPress 6.6.2, WooCommerce 9.3.3,
the plugin working tree, and a test-only payment gateway. Its credentials and
orders are disposable and must never be pointed at a live site or gateway.

From the repository root:

```sh
docker compose -f tests/integration/docker-compose.yml up -d db wordpress
docker compose -f tests/integration/docker-compose.yml run --rm cli sh /workspace/tests/integration/setup.sh
sh tests/integration/run-concurrency.sh
docker compose -f tests/integration/docker-compose.yml --profile matrix run --rm php74
docker compose -f tests/integration/docker-compose.yml --profile matrix run --rm php80
docker compose -f tests/integration/docker-compose.yml --profile matrix run --rm php82
```

`run-concurrency.sh` starts independent WP-CLI containers, hence independent
PHP processes and MariaDB connections. It covers two-browser/two-order claim
contention and asserts the database's unique owner. The Woo smoke creates a real
order and exercises deterministic failed/delayed modes plus the real refund
hook. The gateway exposes capture/ledger-failure and refund controls for fuller
scenarios, but those additional interleavings still need to be added and run;
do not infer that this initial harness proves them.

The deterministic gateway supports `success`, `delayed`, `failed`, and
`capture_ledger_failure` modes through the `mbs_test_gateway_mode` option. It
does not contact a payment provider. Provider-side idempotency and chargeback
webhooks remain gateway-specific and require separate staging verification.

The selected WordPress/WooCommerce versions still run on PHP 7.4. If the
project upgrades to a WooCommerce release that drops PHP 7.4, the declared
support policy must be changed deliberately; do not silently skip the 7.4 job.
