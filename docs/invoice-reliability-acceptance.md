# Invoice and reliability batch — 27 October 2026

Status: implemented locally; not published or deployed. This is not completion of the wider roadmap.

## Invoice changes

- Dompdf-compatible table layout replaces flex positioning; compact A4 spacing keeps ordinary invoices and their payment instructions together. Long invoices retain repeated headings and unbroken item rows, not an unrealistic one-page promise.
- Authoritative tariff calculations capture rate, quantity and kitchen components only when they reconcile exactly to the agreed minor-unit total. New invoices/revisions and ledger items consume that evidence. Stale evidence or historical unknown rates fall back to the agreed total; current tariffs are never applied retrospectively.
- New issue snapshots freeze payments received and outstanding balance. A £125 invoice with £105 received requests £20, not a second £125 payment. Credit notes and receipts do not request another payment.
- Historical issued snapshots remain immutable. Their layout improves, but missing historical rates or issue-time payments are not invented. Existing immediate schedules explicitly acknowledge payments already made.

## Reliability changes

- Protected admin edits and hirer requests commit booking, invoice revision, audit, request resolution, HA intent and selected email intent together. Failure injection verifies rollback. Stale row/request checks prevent silently overwriting another change.
- Canonical recurring approval/extension/cancellation and legacy bulk status paths stage integration/customer intents before commit. PDF rendering, HTTP and mail transport run outside domain transactions. Worker-only acceptance timestamps distinguish queued from transport-accepted; inbox delivery is never claimed.
- Workers discard superseded approvals/edits and use guarded post-transport updates. Access readiness shares the interval service and preserves estate exclusions and payment-enabled checks. All-day confirmation emails no longer assume times exist.
- Google projection and reminders use shared intervals; projection keys and ownership rules remain intact. Occupancy reports distinguish booked, buffered, closed and usable hours, with parent/child/sibling semantics retained.
- General/accounting CSV generation reads a consistent database snapshot, fails without partial output, and enforces 20,000-record/5 MiB bounds. Browser retries read frozen, private, user-bound bytes (three snapshots per account, one-hour expiry). Range requests, checksums, nonce checks and cleanup are implemented. MCP exports retain direct CSV, not cookie-dependent redirects.
- Outbox diagnostics report unknown reads honestly and expose age, next due and expired leases. Attention items have responsible roles/ages; booking detail and authorised API include payload-free access readiness.

## Local evidence

- Root PHP programs, syntax checks, selector/calendar/extras JavaScript checks and MCP schema/dispatch checks run locally. Admin parity intentionally identifies the private browser download separately from REST actions.
- Synthetic Dompdf PDFs: ordinary and bank-detail invoices each one page; 80-line invoice paginates across eight pages. Rendered ordinary/bank/long-last pages visually inspected.
- New disposable WordPress/MariaDB scenarios: invoice layout/atomic confirmation (11 assertions), booking edits/outbox failures (19), paid-venue internal/free-campsite series outbox (27), concurrent accounting export (1,013). External HTTP and customer mail intercepted.
- Existing real WordPress review suite (278 assertions), access delivery and cross-site hardening pass. The invoice flow fixtures require a fresh database because they deliberately retain fixed-date records; rerunning against their previous records produces occupancy conflicts, not a valid clean acceptance run.

## Release gates and remaining limits

- Run the full hosted PHP/WordPress/WooCommerce/concurrency matrix, review the final package, publish a new unified version and verify both independent profiles after deployment. No production records or Google events were changed by these tests.
- Before rollout, verify the WordPress options table is InnoDB on each HA-enabled site. Atomic staging fails closed if its storage is nontransactional or cannot be verified; do not silently convert a production core table.
- Browser HTTP Range/nonce/session acceptance, keyboard/mobile checks and production scheduler observation remain release/operational checks, not proven by unit tests. Private snapshot CSVs contain personal data and expire; they are not permanent backups or accounting postings.
- HA delivery remains at-least-once with coalescing/retries, not exactly-once. Older legacy writers and unchanged rejection/no-change paths have not all been redesigned as one new application service.
- Existing payment deadlines and issued invoices are unchanged. No new policy resolves legacy deadline ambiguities. Attention's overdue-invoice projection follows issued due dates; it does not invent a unified legacy one-off deadline.
- Hardware/accounting provider integrations, stable resource IDs, organisations, equipment/waitlist, private compliance and cross-site overview remain separate roadmap slices; see remaining-roadmap.md.
