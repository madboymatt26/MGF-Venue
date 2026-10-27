# Remaining programme — delivery and acceptance

Authorised scope: the full remaining roadmap, confirmed 24 October 2026. Baseline: 3.40.0 is live on both sites. A checked-in plan, disabled scaffold or successful unit test is not proof of production completion.

## Invariants for every release

- Preserve Needham's payment deadlines and issued document/recipient/price snapshots. No retrospective terms acceptance.
- Preserve Stowmarket's free campsite operation, estate access-email exclusions, multi-space and Whole Park dependencies; preserve Needham Whole Centre dependencies.
- New optional modules start disabled. Payment-related UI and writes also require their existing payment-feature gates.
- Preserve every estate-owned Google event. Uncertain reconciliation never authorises writes; a reliable, existing owned linkage is different from an overlapping candidate.
- Development tests never use production bookings, emails, payments, documents, access codes or calendar writes. Keep rollback packages and compare live settings/counts before and after deployment.
- OSM remains the accounting authority; exports are not postings, and imported bank transactions must not be duplicated.

## Delivery slices

| Slice | Required implementation and acceptance | State |
| --- | --- | --- |
| Reusable extras | Optional catalogue in existing charges UI; exact money/quantity, server-side current-version checks, archive not delete, immutable invoice item snapshot, accounting mapping snapshot, existing cancellation/credit/archive flow | In development |
| Organisations and billing contacts | Explicit organiser/organisation/authorised billing recipient separation; no label-based portal access; recipient snapshots on new documents; audit and specific-document authorisation tests | Not started |
| Equipment and waitlist | Independent optional modules; interval/quantity capacity under concurrency locks, no double allocation, FIFO staff-managed queue, explicit expiry, no implied booking or automatic customer email; promotion goes through normal booking preview/commit | Not started |
| Private compliance | Quarantined private storage outside webroot, authorised download only, size/type checks, actual scanner adapter, review/expiry and attention tasks; fail closed without durable storage/scanner | Not started; hosting/scanner configuration required for activation |
| Resource identities | Immutable additive IDs with label compatibility, dry-run/checksummed resumable backfill and mappings; preserve original import provenance; whole-site/child/sibling regression tests | Not started |
| Operational reliability | Atomic HA intent/domain commit, shared interval adoption across remaining outputs, closure/buffer-aware usage, resumable consistent exports, shared attention/access readiness and queue diagnostics | Follow-up batch built and locally tested; not released. See [acceptance and release gates](invoice-reliability-acceptance.md) |
| Calendar explanations | Explain evidence/confidence using original references, dates and areas; preserve one-to-many links and estate ownership; no uncertain automatic matching | Protected lifecycle live; explanation improvements outstanding |
| Cross-site read-only view | Independently authorised connections, no shared database, HTTPS/SSRF/redirect protection, encrypted credentials, revoke/failure clears stale remote results | Not started |
| Accounting APIs | Retain OSM reconciliation; optional direct provider OAuth, scoped credentials, mapping review, minor-unit credits/refunds/fees, idempotency, sandbox/recovery testing | Existing OSM/CSV live; direct connectors not implemented |
| Access hardware | Provider-specific time-limited area permissions, cancellation revocation, outage handling and safety review | Disabled; hardware/provider not selected |
| Setup, handover and performance | Per-profile checklist, actionable warnings, mobile/keyboard/screen-reader tasks, query plans and measured 300/3,000/20,000-record datasets | Guide live; full acceptance outstanding |

## Release gates

Each slice needs input-validation, permission, feature-off, stale-write, idempotency and database-failure tests appropriate to its risk, plus real WordPress acceptance. Run the full hosted matrix, inspect the release diff/package, publish a unified version and update both sites with their independent settings retained. Report exactly which slices are complete, deployed or blocked; never call the whole programme complete while any required work remains.

## Decisions which remain outside a code release

Do not choose new payment deadlines. Do not select or activate a lock vendor without the hardware details. A direct accounting connector needs the actual service account and reviewed chart/tax mappings before posting. Private compliance downloads must remain unavailable until private persistent storage and a malware scanner have been verified. Building these disabled configuration paths is authorised; inventing successful external verification is not.
