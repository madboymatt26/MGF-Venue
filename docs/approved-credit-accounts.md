# Approved invoice accounts — build and acceptance plan

## Purpose and policy

Keep request → staff approval → payment as the ordinary flow. Do not collect money before approval or replace the existing 48-hour deposit rule. Allow a genuine organisation to request invoice terms without requiring an existing website login. A request, login, organisation name, pricing tier or BACS payment must never grant credit.

An administrator creates an explicit, dated agreement, lists authorised contact email addresses and chooses invoice terms and whether access may be released before payment. A separate administrator decision binds that agreement to one booking or one recurring series. Nothing is inferred from an organisation name. Pricing and discounts remain independent.

Defaults: module off; no account creation/backfill; no automatic customer enrolment; no unpaid access unless expressly authorised; existing issued invoices and monthly schedules unchanged. The actual billing recipient stays the booking/series contact (a separate billing-contact directory is outside this change). A new agreement, not an edit, is required to change terms. Suspension is reversible and audited, and requires staff review rather than retrospective cancellation.

## Implementation

1. Add transactional agreement and exact-scope assignment tables, schema verification and additive migration. Agreements are immutable except active/suspended state, with optimistic version checks and audit records.
2. Add a Settings-linked account administration page and booking/series cards, using existing theme/disclosure styles. Require administrator capability, POST nonce, fresh target fingerprint, fresh account version, authorised contact, reason and explicit verification. No new main-menu entry.
3. Add an optional public request checkbox stored as an untrusted request marker only. Show it to staff; no account IDs or contact lists are public.
4. Exclude assigned bookings from automatic cancellation. Applying credit to an enrolled booking preserves its original policy and explicitly exempts it in the same occupancy-locked transaction. Cancelled/archived/funds-review records cannot be converted. Suspension/module-off never rearms old deadlines.
5. Recheck current agreement, exact contact and valid dates at approval and access delivery. Access is still denied for unapproved, cancelled, estate or funds-review bookings. Paid bookings retain normal eligibility. Missing/expired/suspended credit cannot grant unpaid access; expose it for manual review.
6. New one-off invoices use agreed invoice days instead of deposits; existing issued documents are never rewritten. Monthly series must retain their existing invoice-managed schedule and matching reviewed terms. Confirmation and reminder wording must not threaten automatic cancellation for credit assignments. Chase only after the immutable invoice due date; overdue credit is a staff decision, not automatic forfeiture/refund.

## Verification and release gates

- Pure policy tests: exact contact identity, validity boundaries, feature-off/free-site gates, request != credit, access opt-in, suspension, cancelled/funds-review protections.
- Real SQL tests: schema, nonce/capability entry points, stale agreement/target, duplicate assignment, rollback on audit failure, expiry exemption preserves deadlines/money, new invoice terms, old invoice immutability, own-series scope, suspension and attention state.
- Existing PHP/integration suites: preserve ordinary approval, expiry, reminders, invoices and free campsite behavior.
- Synthetic browser acceptance: themed cards, clear permission choices, keyboard labels and mobile layout. Do not use real customers or send mail.
- Hosted CI on final commit before release. Build does not imply deployment or activation. No real agreement or booking is changed by development/testing.

## Operational handover

Local acceptance: 85 PHP programs, 23 pure agreement assertions, 52 real SQL assertions and 14 synthetic Chrome checks. SQL coverage includes audited rollback, invoice immutability, account isolation, funds-review/cancellation guards, monthly scope, suspension, feature-off and idempotent/stale reminder handling. Browser checks exercise nonce/capability rejection, explicit permission defaults, stale assignment, keyboard disclosure and desktop/narrow layout; screenshots reviewed. Hosted CI and production preservation checks remain mandatory release gates.

Enable only after reviewing this policy. Create an agreement with authorised contacts, terms, expiry date and the access decision. Bind it before approving the booking; normal staff approval is still required. For an existing issued invoice, its printed deadline remains authoritative; credit assignment changes cancellation/access handling, not that document. For a monthly series, configure and review its normal billing schedule first.

Suspended/expired agreements and overdue invoices appear in Needs attention. Staff reconcile payments, contact the account and decide whether to suspend future credit. They do not silently mark money paid, cancel already-confirmed sessions, revoke a code already delivered, refund, or restore a released slot. Disabling the module stops new grants and unpaid access but does not undo exemptions or erase audit history. Roll back by disabling it and retaining additive tables; review affected upcoming access first.
