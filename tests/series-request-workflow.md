# Hirer series requests

The signed-in portal now accepts change/cancellation requests for one future
session, selected sessions, or the currently booked sessions on/after a cutoff.
Today/past and cancelled/archived sessions are excluded. Account email ownership
is checked server-side; no public token or anonymous series endpoint is provided.
Requests freeze exact occurrence references and dates. They do not include later
extensions, and overlapping pending requests are rejected under the series lock.

## Staff review

Change Requests shows the full scope, notes, current series link and a warning
when the series version has changed. Staff must review terms, charges and refunds.

For cancellations, **Approve listed cancellations** uses the canonical series
cancellation transaction. It locks/revalidates the series, occurrences and request,
credits the affected invoice lines, cancels only those occurrences, increments the
series version and resolves the request in the same transaction. Unselected
sessions stay active. Existing series-change/cancellation notifications are used.
No cash refund or cancellation charge is automatically applied.

Change requests deliberately use a staff-assisted workflow, not automatic
repricing/rescheduling. Staff must make and check the appropriate booking/billing
changes separately, then enter an outcome with **Changes applied — record outcome**.
This records `handled`, not `approved`, and emails that outcome. The button does
not itself execute booking changes. A cancellation can only be manually marked
handled after all requested references are actually cancelled.

Reject records `rejected` conditionally and emails the reason; it changes no
booking. Hirers can see pending, approved, handled and rejected request history
and staff responses in the portal. No schema change is needed.

## Verification

- `php tests/php-series-requests.php`: owner/scope/input/duplicate/failure tests.
- `php tests/php-series-cancel-cutoff.php`: cutoff and exact-selection tests,
  transactional request approval, stale requests, reconciliation/commit rollback.
- `tests/integration/scenarios/series-cancel-cutoff.php`: real WordPress/MariaDB
  invoice credits and portal request approval. Run via the disposable integration
  suite, never on a production database.
- Browser QA still needed on a disposable WordPress site before deployment:
  owner/other account, all three scope controls, keyboard/form validation, duplicate
  submission, staff approve/reject/handled flow, outcome email and portal history.
