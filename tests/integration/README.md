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
docker compose -f tests/integration/docker-compose.yml --profile matrix run --rm php83
```

GitHub Actions uses the following declared matrix. WooCommerce 9.3 requires
WordPress 6.5+ and PHP 7.4+; WordPress 6.6.2 is therefore pinned for every row.
The old-PHP Docker tags supply the declared PHP runtime and `setup.sh` installs
the exact WordPress core version into their shared volume.

| PHP | WordPress | WooCommerce | MariaDB | Runtime status |
| --- | --- | --- | --- | --- |
| 7.4 | 6.6.2 | 9.3.3 | 10.11 | Declared legacy minimum; EOL upstream |
| 8.0 | 6.6.2 | 9.3.3 | 10.11 | Declared legacy compatibility; EOL upstream |
| 8.2 | 6.6.2 | 9.3.3 | 10.11 | Primary integration runtime |
| 8.3 | 6.6.2 | 9.3.3 | 10.11 | Current supported runtime for this pinned stack |

`run-concurrency.sh` starts independent WP-CLI containers, hence independent
PHP processes and MariaDB connections. Database barriers deliberately align
shared-session and separate-session workers inside the observable reservation
critical window. Each ownership race is repeated three times. A separate
mutation control drops the uniqueness guard and bypasses the CAS path, proves
two workers can then win, and requires the normal durable-row assertion to
fail. Migration and catch-up workers use their own contention points.

The behavioural suite creates real plugin invoices, booking allocations,
WooCommerce orders and `WC_Order_Refund` objects. It covers payment/refund
callback ordering and noiseless replay, full/partial-refund repayment, exact
online order values (under/over, coupons, fees, edits, currency and stale
generations), partial/cumulative/reordered cancellation-credit cash refunds,
GMT/BST/positive-offset/DST-boundary reservations, payout-aware OSM
classification/quarantine and ledger-event recovery, accounting
boundary/non-GBP credit exports, safe-modification
failure/retry, complete same-name schema corruption, forced migration operation
and verification faults, mixed financial-history schema-5/6 upgrades, InnoDB
rollback, more-than-100-series catch-up, and overlapping workers. The authenticated MCP test sends a real MCP tool call
through the REST/admin compatibility bridge to the disposable WordPress site.

The dependency-free `tests/php-*.php`, admin-parity, and MCP-schema programs are
reported separately in CI because some are structural/source assertions. They
are useful regressions but are not counted as real-service behavioural proof.

The adversarial scenarios use `MBS_Audit_Assertions`, whose state belongs to a
dedicated object rather than ambiguous `wp eval-file` globals. The runner first
executes an isolated intentionally-false assertion and succeeds only when that
WP-CLI process exits non-zero. A failed scenario cannot print the final success
message, and the shell wrapper propagates real scenario exit codes to the job.

The deterministic gateway supports `success`, `delayed`, `failed`, and
`capture_ledger_failure` modes through the `mbs_test_gateway_mode` option. It
does not contact a payment provider. Provider-side idempotency and chargeback
webhooks remain gateway-specific and require separate staging verification.

The selected WordPress/WooCommerce versions officially allow PHP 7.4. If the
project upgrades to a WooCommerce release that drops PHP 7.4, the declared
support policy must be changed deliberately; do not silently skip the 7.4 job.
