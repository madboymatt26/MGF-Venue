# Unpaid booking expiry and money-held review

Status: approved requirements; implementation in progress. No production activation.

## Agreed policy

* Site opt-in. Only newly approved, individually billed paid hires are enrolled.
  No backfill or retroactive enrolment, including existing unpaid bookings.
* Approval starts a fixed 48 elapsed-hour deposit deadline. Freeze the approval
  timestamp, deposit amount, policy version and Europe/London display timezone.
* Final cancellation cutoff is 08:00 Europe/London on the day before arrival.
  This is distinct from an invoice's earlier balance due date, which stays intact.
* If approval is less than 48 hours before that cutoff, full payment is required
  by the cutoff instead of a separate deposit grace period. Staff must see and
  acknowledge the exact shortened deadline before approval. At/after cutoff,
  no automatic enrolment or immediate cancellation: explicit manual review.
* At deposit expiry, insufficient deposit cancels the hire and releases the slot.
  At final cutoff, any remaining balance does the same even if a deposit was paid.
* Free/Scout/estate/HP Business, offline-invoice and consolidated recurring-series
  billing remain outside this policy. Separate additional-charge invoices do not
  control venue cancellation or access. Turning site payments off suspends expiry.
* A manager can grant a dated extension or exempt an enrolled booking with a reason.
  Changes must be audited and clearly visible; no silent resets on edits/reapproval.

## Customer communications

Approval must state the amount and exact deadline, and: “If your deposit is not
paid within 48 hours of approval, your booking will unfortunately be cancelled
to make the venue available for others again.” Short-notice approvals instead
state the full-payment deadline. Show the final cutoff in either case.

Warn before expiry (deposit: 24 hours before; final: 24 hours before, or immediately
when a shortened approval leaves less time). Existing payment reminders must
include the next applicable cancellation deadline and correct amount paid/owing.
No warnings for settled/exempt/cancelled bookings. Do not send a stale warning
after a payment, extension or cancellation. Sending an ordinary chase must not
move either deadline or consume the policy-warning idempotency key.

Cancellation gets a separate email: reference, dates, cancellation reason, money
paid, no access code, and a request to contact staff if payment has just been made.
Never promise forfeiture or refund. A deposit-paid cancellation says the payment
is awaiting manual review. Admin gets an email with a booking-review link.

## Money-held review and refund mechanism

Persistent state, not only a dismissible email: “Cancelled — money held; review
required” in the booking and Needs attention dashboard/MCP. Record gross receipts,
verified refunds, retained amount, unresolved amount, reason, reviewer and time.
Archiving must not hide unresolved reviews; a refund must never reopen the booking.

Actions: retain all/some with a reason; refund all/some through the original
WooCommerce order's native refund controls; record a bank refund already completed
with amount/date/reference; close only when all held funds have an evidenced
refund or retention decision. Partial refunds leave the remainder visible.

Opening an order or recording a bank refund is not itself a gateway transfer.
Gateway success must be verified through the order/refund ledger before recording
it. No automatic refunds. No reuse of the legacy “mark refunded” action that
rewrites amount_paid to the booking charge and sets status paid. Do not overwrite
historic receipts, invoice amounts or cancellation status to clear a review.

## Safety and failure handling

Re-read status, amount, receipts, payment reservations and deadline under shared
booking/payment locks immediately before expiry. Integer minor units only.
Settled or processing/captured-but-unreconciled payments block destructive expiry;
ambiguous payment state raises a visible review rather than guessing.
Invalidate unpaid checkout orders/links on cancellation. Captured late payments
must become money-held review, never silently reopen a released venue slot.

Cancellation, access invalidation, review creation, audit and mail intents commit
atomically. Fail closed if audit/outbox/HA-intent persistence fails. Notifications
use stable idempotency keys; duplicate workers cannot duplicate state transitions.
Google follows existing ownership-safe cancellation rules; protected external
events require review rather than deletion. Preserve all financial documents.
Scheduler uses UTC instants calculated from local dates; daylight-saving tests
cover 47/49-hour weekends. 48 hours means elapsed hours, not two calendar dates.
08:00 is the nominal cutoff; a delayed scheduler must not cancel a hire after it
has begun. Raise an urgent manual-review item instead. Cron health must expose
missed deadlines. Never claim exact-time/inbox delivery from a queued message.

## Rollout and acceptance gates

Feature defaults off on both sites. Needham activation requires preview of eligible
new-approval policy, customer wording and tested refund review; Stowmarket stays
off/free. No production booking/customer-email tests. Retain rollback package;
rollback must not erase policy snapshots/reviews or unsend cancellation messages.

Unit: boundary times, DST, exact/partial/overpaid amounts, deposit off, short notice,
exemptions, extension, stale reminders, policy snapshot stability.
Real WP/DB: atomic approval/enrolment; warning/cancel outbox; payment-vs-expiry race;
duplicate workers; failure injection; late capture; partial refund; bank-refund
evidence; retention; archive visibility; refunds never reopen; original invoice
and unrelated-site fingerprints; permissions/nonces/stale edits/HTML escaping.
Browser: desktop/mobile/keyboard approval warning, exact deadline, action review,
refund links and confirmations; free-site controls absent. Full existing CI must
pass before publication. Deployment/activation status must be reported separately.
