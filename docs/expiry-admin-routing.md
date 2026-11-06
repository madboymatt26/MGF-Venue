# Payment-expiry administrator alert routing — 3.45.1

The 3.45.0 expiry notifier used only the primary venue administrator email,
ignoring configured additional notification addresses. This patch shares the
existing ordinary-notification recipient policy through a public validated,
trimmed, case-insensitively deduplicated helper. Existing private callers remain
compatible. No setting, cancellation deadline or customer recipient is changed.

Each expiry admin recipient gets an independent transactional outbox row. The
primary retains its original idempotency key; additional recipients use stable
hashed suffixes. Any enqueue failure propagates to the caller's transaction, so
cancellation cannot commit with only part of its notification intent persisted.
Hirer cancellation/warning keys and recipient selection remain unchanged.

Historical repair is explicit, not automatic: read the accepted original admin
row, exclude its already-addressed recipients, and enqueue only missing configured
admin copies under separate deterministic repair keys. Never reset a sent row,
re-run the booking cancellation, or resend the customer's email. Verify transport
acceptance and, where accessible, actual receipt separately.

Coverage: recipient validation/deduplication, primary-key preservation, per-address
idempotency, customer isolation, enqueue failure propagation, real SQL rollback
when an additional-recipient insert fails, and unchanged expiry race scenarios.
