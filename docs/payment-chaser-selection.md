# Payment reminder selection repair

Implemented locally, 4 November 2026. Not released or deployed.

The confirmed-booking query previously selected the oldest 20 rows before
checking monetary balance and series billing treatment. Free internal Scout
rows therefore repeatedly occupied the entire batch. Live read-only evidence
showed more than 20 such rows ahead of eligible unpaid external bookings;
automatic reminders were enabled and the scheduled job was completing.

Both confirmed and deposit-paid selection paths now exclude Scout-use rows
and balances of one penny or less in SQL. Deterministically ordered candidate
pages are read until the existing eligible batch cap is reached or candidates
are exhausted. The existing billing-treatment policy filters consolidated
series before they consume a batch slot. Candidate selection finishes before
mail sends update chase tracking, avoiding self-induced offset shifts.

Existing age/deposit windows, reminder interval, per-booking chase limits,
20/10 batch caps, feature switches and manual-send behaviour are unchanged.
This does not unify the separate invoice/terms policies or repair the legacy
sender's mail-acceptance tracking. No cron/manual mail action is used as a test.

Verification: all 83 dependency-free PHP regression programs passed locally
on PHP 8.5, including 21 new selection assertions and the existing 57
billing-treatment assertions. PHP syntax and git whitespace checks passed.
The synthetic selection test covers free and settled rows, NULL paid values,
200 excluded consolidated occurrences spanning pages, one-off and orphaned
legacy eligibility, both batch caps and exhaustion without sending mail.
The existing CI suite discovers the new php-* test automatically. Hosted
PHP-version and real WordPress/database verification remain release gates.

Rollout: release through normal review/CI. On paid sites inspect the eligible
backlog before activation because previously starved reminders may now run on
the next scheduled tick. Respect current chase timestamps; do not reset them
or force a bulk run. On free sites retain the reminders-disabled feature gate.
After release inspect selection and scheduled outcomes without creating test
bookings or sending synthetic customer reminders. Rollback is the previous
plugin package; no schema change or data migration is needed.
