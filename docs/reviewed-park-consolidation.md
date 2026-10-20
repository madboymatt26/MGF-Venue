# Reviewed Whole Park exceptions

Administrator-only, Stowmarket-host/campsite-only POST
`/mathlin/v1/admin/reviewed-park-consolidation`. No automatic run on update.
The original completed 26-date migration remains immutable.

Preview body: `operation: preview`, six explicit `refs`, a canonical `purpose`
already on a source, replacement `notes`, a reviewed identity `reason` and
`acknowledge_differences: true`. Inspect returned dates, exact seconds, approval,
sources and target. Commit uses identical inputs plus `operation: commit`,
the returned `fingerprint` and `confirm: true`. Rehearse with `dry_run: true`.
No booking, notification or Google change occurs during preview/rehearsal.

Only standalone future legacy rows with identical ownership, occupied date range,
times, flags and approval are eligible. All six configured Whole Park children
are required. Purpose/notes differences require explicit human event-identity
review. Financial history, invoices, charges, pending requests, active HA,
enabled Google sync, saved source Google references, other conflicts and blocked
areas reject the operation. Every occupied day is checked.

The replacement and recoverable source archives commit together with private
database before-images. Excluded rows are compared byte-for-byte; repeat commits
return the original result. Existing restore guards prevent restoring superseded
sources independently. Recovery is a separately reviewed operation using the private
snapshot, never the old migration/import. No customer mail or Google operation.

## Partial-area bookings: separate feature requirement

MGF currently stores one space per booking. Whole Park is the existing six-area
parent; it cannot safely represent five areas while leaving Paddock available.
A future multi-area model should use one booking reference with an explicit set of
physical area allocations, with the same dates/time for every selected area.
Availability must lock/check the complete area set in one transaction, including
Whole Park dependencies; cancellation/edit/recurrence/portal/MCP/calendar and mail
must use that same allocation model. Pricing and capacity must be explicitly
defined rather than summing configured limits. Keep this opt-in for free campsite
mode and preserve normal Needham billing. Do not implement as a renamed Whole Park
or presentation group: those would misstate occupancy.

The May example therefore needs one five-area allocation for 28–31 May and a
separate Paddock booking for 25–31 May; it is deliberately rejected by this
Whole Park-only consolidation tool.
