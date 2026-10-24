# Reliability milestone: administrator handover

## Deployment boundaries

3.40.0 is one shared plugin release for both profiles, not a shared database or configuration. Keep a current hosting backup and out-of-WordPress recovery available. Retain the previously installed release package for code rollback; do not overwrite financial history or Google estate events to roll back code.

This release does not change payment deadlines, issued invoices, Google reference choices, access codes or estate/Scout eligibility. Do not enable optional features as part of an update. The remaining roadmap is in the implementation register.

## Daily administration

- All Bookings → Needs attention: read pending approvals/change requests, overdue ledger invoices, failed mail, delayed jobs and protected Google review references. Opening a link does not approve or send anything. This is a capped summary; it does not replace checking complete lists. Legacy one-off invoice balances still require their booking/invoice detail review.
- Integration health: see schema/profile, current feature switches, mail transport counts, job observations and Home Assistant outbox state. An unobserved job is not proof of success; verify the host scheduler if timing matters. A completed PHP hook is not proof of customer inbox delivery.
- Home Assistant failures: inspect the endpoint and booking first, then explicitly queue the reviewed retry. A stale intent/version is rejected. The normal worker delivers current booking state; the button does not immediately send HTTP or customer mail. Disabled endpoints retain queued intents.
- Calendar Sync: preserve estate-created reference events. Review overlap explanations and original provenance before changing links. This release neither resolves uncertain matches nor enables a paused sync automatically.

## Settings

Settings → Operational controls exposes reporting opening/closing hours and the campsite legacy read-only compatibility switch. These do not change hire prices. The legacy setting remains campsite-only and should not be toggled merely to clear an unrelated error.

Optional setup/clear-down buffers are per configured resource, zero/default off, and require explicit acknowledgement to enable. Buffer minutes reserve physical occupancy before/after the booking but never alter billed hire times. Parent/child conflicts remain authoritative; separate sibling areas do not block one another. Review existing adjacent reservations before enabling a new buffer policy.

Reports show booked session hours, days, reservation counts and estimated attendance with parent/selected-area expansion. Do not add resource totals together as distinct reservations. These metrics currently exclude buffers and blocked-date closures; they are not measured attendance or camper nights.

## Accounting and documents

Reports links to the existing OSM payout/bank reconciliation workflow. Preserve its sandbox/live configuration and category mappings. Never post the same receipt a second time through an added integration or CSV import.

Xero, Sage and QuickBooks downloads are CSV layouts, not direct connections. Review account codes, tax and credit-note/import mappings before use. Exports at the 10,000-booking boundary stop with an explicit error: narrow the date range rather than accepting a partial CSV. Database failures must not yield a healthy-looking partial file.

New public one-off bookings retain their accepted terms version and timestamp. Existing/imported/staff bookings without evidence remain labelled as such; a release must not fabricate historical agreement.

## Verification after installation

1. Read capabilities and configuration before/after; compare payment switches, resource hierarchy, prices, access and calendar policy.
2. Verify the installed version and schema, public booking/manage pages, staff list/detail, reports and settings without submitting a real booking.
3. Check the complete booking count and representative parent/child availability; leave pending requests and balances unchanged.
4. Check mail queue and scheduled jobs. Do not use bulk customer resend or a calendar write as an update test.
5. If an error occurs, retain the error correlation and hosting logs; revert plugin code using the retained release or hosting recovery, preserving financial and estate-calendar data.

Smart locks, organisation billing contacts, extras, private compliance documents, stable resource-ID migration, cross-site overview, waitlists and new direct accounting APIs are not supplied by this milestone.
