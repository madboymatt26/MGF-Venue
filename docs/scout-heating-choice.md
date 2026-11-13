# Scout heating choice — dual-scope rollout contract

## Current release candidate

WordPress 3.47.1 and HA component 2.1.0 replace the withheld 3.47.0 design.
3.47.0 passed its original tests but was rolled back after live inspection found
that its shared occupancy sensors also drove oven/hob and lighting controls.
It remains a prerelease. Do not install the obsolete patches/2.0.0 coordinator.

The component source is reconciled to the reviewed installed 2.0.0 Python files,
English strings and manifest, preserving its authentication, options, interval
parser and entity identities. Changes are applied to that baseline, not the
repository's former 1.1.0 implementation. Existing installed translations/brand
assets are not overwritten by the targeted production update.

## Policy

- Scout series always have plugin heating off; independent HA Scout schedules
  remain authoritative. One-off Scout bookings default off and can be opted in
  explicitly by booking managers on the booking detail page.
- External hire heating is unchanged. Billing, status, access, invoices, booking
  occupancy and customer notification rules are not changed by a heating toggle.
- The dedicated handler checks permission, nonce, whole-row optimistic version,
  active one-off scope and occupancy lock, then uses the transactional change
  service. Intent failure rolls back the metadata change.
- Opt-out is heating_request_removed, never a claim the booking was cancelled.
  Workers re-read current eligibility before delivering stale queued intents.

## Two independent sensor scopes

The authenticated /bookings/occupancy-today endpoint retains every approved
booking with explicit boolean scout_use/heating_required flags and
integration_scope=booking_occupancy. The existing /today and /upcoming heating
feeds stay filtered; public calendars and general availability stay unchanged.
The new endpoint has the same booking-read permission requirements.

The coordinator validates the new contract and preserves last-valid data/timers
on failed refresh. Both heated and unheated bookings get exact entity boundary
updates. Only heated bookings get heating start/end events and gap suppression.
Successful changed polling data cancels obsolete timers, including empty data;
unchanged entity-boundary notifications do not rebuild timers repeatedly.

Existing nine general-occupancy entity unique IDs/names remain unchanged.
Nine additional Heating entities have distinct unique IDs ending _heating.
Their model is a filtered view of the same validated coordinator data; an
unheated earlier booking cannot hide a later heated booking's preheat window.
Whole Centre normalises to the installed Whole Headquarters parent alias inside
HA only; venue_space retains the original. Stored WP resource labels unchanged.

Only four heating consumers are rewired by verified old-to-new entity ID mapping:
Scout thermostat overlap guards, booking HVAC controller, ASHP target controller,
and the booking load forecast. Oven/hob and lighting automations are byte-for-byte
unchanged. Reversing the mapping must reproduce each original configuration
exactly: no schedule, condition, setpoint, device target or action change.

## Verification and activation gates

- PHP feed/policy/outbox tests include heating scope and retained general
  occupancy. Existing auth/CAS/SQL rollback and keyboard/narrow browser tests run.
- Nine synthetic tests execute actual 2.x sensor code: retained Scout occupancy
  and kitchen entitlement, external hire, distinct summaries, next-hire preheat,
  opt-in/out, parent space, end boundary and disjoint 9+9 registration.
- Coordinator tests cover removal, replacement, failed refresh, duplicate/setup
  notifications, opt-out occupancy boundaries, unload and wrong-producer refusal.
- Pure overlap-guard tests cover isolated rooms, preheat, kitchen overlap,
  unavailable states and original Scout attendance cutoff.
- Final exact-head CI must pass before production installation.
- Back up live DB/plugin and selected HA files/automations; revalidate baseline
  hashes immediately before writes. Deploy WP producer before activating HA.
- Stage the reviewed component files only, restart HA once, verify 18 entities,
  resolve the actual registry IDs, then rewire only the four consumers.
- Inspect natural traces; do not run a physical thermostat test or manufacture
  production bookings/payments/emails. Maintenance booking remains heating off.
- Compare booking/payment/email/policy/settings fingerprints before and after.

## Rollback

Retain additive metadata and audit history. Restore only the changed component
files and selected automation configurations, never an old whole automations.yaml.
Restore the previous verified WP ZIP, restart HA, and verify general sensors.
Do not switch off physical heating as a rollback shortcut. A failed activation
must not be reported as a successful deployment.
