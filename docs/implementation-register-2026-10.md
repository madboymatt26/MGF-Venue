# Review implementation register

Status: development work, not a release or production deployment.

## Confirmed decisions

- Preserve Needham's current payment deadlines and all issued document terms. A policy change requires a separate decision.
- OSM is the primary accounting workflow. Preserve its existing payout-to-imported-bank-line reconciliation and idempotency; do not create a second posting path.
- Xero, Sage and QuickBooks currently have optional CSV layouts, not direct API connectors. Label that distinction in Reports and require account/tax/import mapping review. Do not imply an export was posted or reconciled.
- No smart-lock provider has been selected. Hardware integrations remain disabled and cannot be considered implemented.
- Keep optional physical setup/clear-down buffers disabled by default. They reserve time, never change billed hire times.
- No live site, booking, Google event, accounting entry or customer email is changed by this development batch.

## Implemented locally; release acceptance still required

- Shared London-time interval service, DST-aware exports and inclusive stay dates with exclusive calendar endpoints.
- Complete paged approved-booking feeds and privacy exports; no 100-row privacy truncation.
- Durable coalescing Home Assistant outbox, HTTP success validation, bounded retries and safe concurrent-intent handling. HTTP no longer runs in booking writers.
- Durable feedback queue with bounded catch-up and post-transport bookkeeping; enqueue failures no longer mark feedback sent.
- Verification expiry, time-sensitive queue priority and policy pauses that do not consume transport retries. Stale/paid invoice reminders are rejected at dispatch.
- Atomic mailbox rate budgets, narrower PDF temporary storage, explicit legacy-read-only setting and feature-specific Scout migration marker.
- Indexed target projection for native managed calendar events, retaining conservative full provenance checks for legacy links.
- Occupancy report service with parent/child expansion, interval union, consistent reporting periods and explicit invalid-source reporting.
- Versioned one-off terms evidence; historical/imported bookings are not retrospectively marked as accepted.
- Optional resource buffers, settings validation and unchanged disabled behaviour.
- Staff attention summary, job observations and integration health. Attention is read-only; it does not approve, send or chase.
- HTTPS-only credentialed bridge access without redirects, additional calendar read resources and expanded CI runtime coverage.

## Evidence so far

- 72 isolated root PHP test programs passed.
- Plugin PHP syntax checks passed.
- Real disposable WordPress 6.6.2 / MariaDB / PHP 8.3 acceptance: 275 assertions passed, domain changes rolled back. Includes buffer-off parity, child/sibling/whole-park conflicts and real attention queries.
- MCP schema smoke: 23 tools passed. Admin parity: 52 handlers and 52 privileged hooks. Additional-charge mocked dispatch passed.
- These are local results, not hosted CI or live-site acceptance. WordPress.org update checks in the portable local runtime had a certificate/trust warning; this is not production delivery evidence.
- The new loopback HTTPS CI proxy and full expanded hosted matrix have not yet run on GitHub.

## Still outstanding — do not describe the whole proposal as finished

- Complete cross-output interval adoption and regression coverage for access/reminders/Google; preserve existing proven policy paths until characterised.
- General large export resumability, concurrent-export consistency and explicit database-failure behaviour beyond the covered feeds/privacy routes.
- Review Home Assistant intent atomicity with the booking commit and an operator recovery/retry workflow. Current outbox health is diagnostic, not exactly-once delivery.
- Finish shared attention state on booking detail and MCP, access readiness, owners/ages, truthful queue diagnostics and task-based UI acceptance.
- Complete legacy calendar recommendation explanations without changing any estate events.
- Organisation/billing contacts; optional extras and amendments; private compliance document workflow.
- Stable resource identifiers and additive migration/backfill tooling with dry-run counts and recovery mappings.
- Optional cross-site read-only overview; waitlist/equipment requirements and workflow decisions.
- Direct accounting connectors require provider-specific OAuth, mappings, import/post semantics, idempotency, credit/refund handling, sandbox tests and recovery. Existing CSV layouts are not a substitute for those controls. OSM remains the priority.
- Smart-lock hardware/provider decision and end-to-end revocation tests.
- Full volunteer handover/setup guides, browser/mobile/accessibility verification, performance measurements and query-plan evidence.
- Run the complete hosted integration/concurrency matrix, review the final diff, choose a release version and obtain publish/deploy authority. No production release has been created by this batch.
