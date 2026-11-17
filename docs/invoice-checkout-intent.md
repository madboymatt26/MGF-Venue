# Invoice checkout intent — 3.47.2

Problem: an invoice bearer-link HEAD request could acquire the 20-minute checkout claim before the real browser arrived. A separate session then failed with an ownership error. The caller was not proven to be a particular mail-security provider.

Invoice payment links now validate the existing bearer token and current payability, then:

- HEAD returns 200 without acquiring or renewing a reservation or changing the cart.
- GET shows a read-only, no-cache confirmation page and a deliberate POST button. It does not generate a new payment token or change invoice state.
- POST requires the bearer token, explicit confirmation, and a WordPress nonce tied to the current user, invoice reference, invoice version and outstanding minor-unit balance. A changed balance/version or expired nonce requires reopening the original link.
- Only validated POST may acquire/renew the existing exclusive reservation and prepare the cart. The same session can resume an active claim. Competing sessions and bound/processing orders remain blocked. Cart setup failure releases only the acquired active claim. The final redirect uses 303.

No schema migration, invoice reissue, token rotation, customer email, credit, payment or booking mutation is required. Existing invoice links continue working. Legacy one-off booking-payment links (`mbs_pay`) are outside this invoice-specific patch.

Regression evidence: `php-invoice-checkout-intent-unit.php` exercises the real handler with side-effect-counting doubles. The isolated WordPress/Woo browser scenario covers HEAD and GET probes, a real button click, bad confirmation, stale balance, same-session resume, competing sessions and simultaneous POSTs, with direct database probes for booking/email isolation. Existing real financial/concurrency scenarios retain binding, capture, refund and reconciliation coverage.

Release gate: full PHP suite, hosted supported-runtime/concurrency checks and real-browser acceptance must pass before deployment. Install on Needham only with a recoverable plugin/database backup, verify exact release/version and financial/configuration fingerprints, then perform only read-only HEAD/GET verification on the affected live invoice. Never submit a real hirer's confirmation form or create a live test payment. Stowmarket is not part of this deployment.
