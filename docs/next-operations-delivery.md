# Next operations programme — scope and acceptance

Requested 27 October 2026. Production baseline is shared 3.42.0, not completion of the wider programme. Every feature retains independent site settings; new optional modules default off. This document distinguishes implemented work from future requirements.

## Increment A — built locally, not released

- Legacy internal Scout bulk cancel/edit/reopen/delete/extend: occupancy lock, transaction covering the batch and parent summary/audit, bounded complete reads, financial-history failure blocks mutation, no unknown scope falling back to entire-series deletion. Reopen checks current conflicts/closures. Real conflicts may be explicitly skipped during edit/extension; database failures roll back, not silently skip.
- Calendar matching explanations: per-candidate original-reference, area/date lineage, ambiguity and conservative confidence labels in review UI and authorised read API. No new matching authority, automatic choices or Google writes. Individually labelled checkboxes preserve native keyboard navigation.
- Invoice-modal keyboard containment, Escape and opener focus return; async responses cannot replace a newer document or a closed modal. Calendar table caption and column headers added.
- One-off request rejection now commits the decision, audit and durable notification together; worker delivery checks the current decision/recipient. A failed queue/audit write cannot send a rejection, and availability-read failures are not treated as proof that a request should be declined.
- Profile-aware, read-only Staff guide in the plugin: daily checks, approval/change workflow, Google reconciliation, access, recovery and paid-only invoice/extras/export handover.
- Measured local MariaDB benchmark: synthetic 300/3,000/20,000 records. Calendar query: 0.947/1.272/1.448 ms; 50-series summary query: 1.680/8.010/46.186 ms; first-100 search: 4.392/6.597/1.422 ms. Each measured path used one SQL query; series lookup used idx_series. These are one-machine warm-run observations, not throughput/SLA claims or evidence of full production-load/accessibility acceptance. Fixtures roll back.

## Increment B — organisations and billing contacts (not built)

Yes, this must integrate with existing pricing tiers. Current pricing authority is the account's assigned tier, copied to a booking; the free-text organisation label is not pricing authority.

- Add immutable organisation IDs, active/archived profiles, explicit organiser membership and authorised billing contacts. Membership must be verified or assigned by an authorised administrator; matching a name/email domain is not sufficient.
- An administrator may associate an organisation with an existing tier. Do not add an independent discount engine. Define and display the chosen tier at booking preview; preserve explicit existing-booking tiers and issued prices. Never reprice history when a membership, billing contact or tier changes.
- Separate booking organiser (booking/access/operational notifications) from selected invoice recipient. Snapshot the selected billing name/address/email per issued document, including amendments/credits. An accounts contact must not receive access codes or all organisation booking records merely by being a billing contact.
- Authorise each invoice independently. Verified billing-contact access must be limited to explicitly assigned documents, and revocation/future selection must not silently rewrite historical recipient evidence.
- Before implementation acceptance: paid/free feature-off cases, guessed organisation ID, name collision, unauthorised membership, stale membership/version, billing-recipient change, old-document access, consent/assignment audit, no duplicate email/payment/OSM posting.

## Increment C — equipment and waitlists (not built)

- Equipment is physical inventory (e.g. tables/projector/camping equipment), not an additional-charge catalogue. Track total/serviceable quantity and interval-based allocations, including setup/return time. An optional charge references the existing invoicing/extras workflow; free allocation must work with payments disabled.
- Reserve quantities transactionally under a shared capacity lock. Pending/confirmed/cancelled/returned states need explicit rules; no double allocation, negative stock or silent substitutions. Changing a venue booking must revalidate associated equipment before committing. Invoice credits and stock release are distinct operations.
- Staff-managed waitlist with request/contact/areas/times, FIFO order and expiry; joining does not reserve space, charge or promise a booking. Promotion must go through normal validated booking preview/commit, not bypass conflicts. No unsolicited automatic customer message.
- Acceptance: simultaneous last-item allocation, overlapping/adjacent/multi-day sessions, blocked inventory, cancelled/changed bookings, partial quantities, failed commit, expired waitlist entry, feature-off paths and audited staff promotion.

## Increment D — stable resource IDs and migration (not built)

Purpose: a room/area has a permanent identity independent of its display name. This eventually lets staff rename “Main Scout Hall” without losing links. It does not combine rooms, remove areas, change parent exclusivity or synchronise bookings between sites.

- Add site-local immutable IDs alongside existing labels, with parent IDs and explicit legacy aliases. Preserve original imported labels/references for audit and Google provenance. New reads/writes must understand IDs and old labels before migrating any data.
- Migration first generates a read-only plan: affected row counts, exact mappings, unresolved labels and a checksum. Staff approves that exact plan. Backfill is bounded, resumable and idempotent, and refuses stale mappings. Preserve recoverable before-images; do not guess aliases from similar text.
- Renaming is only offered after dependency consumers use the ID/alias resolver: availability, multi-area/parent rules, closures/buffers, series, prices, invoices, calendars, email/access and exports. Merely adding an ID column is not feature completion.
- Acceptance: unchanged Whole Centre/Whole Park conflicts and siblings, historic invoices, old API payloads, archived imports, multi-event Google links, interrupted migration/retry, concurrent booking creation, unknown labels, stale checksum and verified rollback.

## Increment E — cross-site read-only overview (not built)

Purpose: authorised staff see both venues' availability/workload and operational warnings on one dashboard. It is not a combined booking database, shared user directory, shared pricing configuration or permission to modify the other site.

- Each site grants a separate least-privilege connection. Default overview shows minimised operational summaries; customer names/contact details/payment information require a separately justified permission. Never reuse organisation membership as cross-site authority.
- Use a dedicated read-only endpoint and explicitly approved HTTPS origin, encrypted credentials, bounded responses/timeouts and no redirects. Validate DNS/IP/ports to block internal/metadata/loopback targets and DNS-rebinding paths. Do not accept arbitrary URLs as proxy targets.
- Show per-site last successful read and errors. A failed/revoked connection must clear cached remote details rather than show them as live. Revocation must remove credentials and caches. Read-only means no booking/status/email/payment/calendar writes from the dashboard.
- Acceptance: both site profiles, least-privilege denied reads, revoked/expired credentials, malicious/redirected/private hosts, malformed/oversized responses, timeout, stale cache, independent settings and no accidental remote writes.

## Release and remaining acceptance

Increment A local evidence: 79 standalone PHP programs, four JavaScript control suites (including invoice modal focus/stale-response tests), 18 legacy-series failure-injection assertions, 13 request-rejection assertions, the existing Scout-series administration suite and 278 review-reliability assertions passed. Benchmark fixtures roll back. Increment A still needs hosted compatibility CI and final visual/assistive-technology checks before release. It is not a whole-plugin accessibility certification. Broader legacy writers, realistic concurrent HTTP load, query plans for other reports and handover by a new volunteer remain acceptance work.

Increments B–E are defined here, not implemented scaffolds or live features. Private compliance uploads, accounting-provider connectors and hardware access are outside this newly prioritised batch and remain on the wider roadmap. Needham payment deadlines remain unchanged pending the separate business decision.
