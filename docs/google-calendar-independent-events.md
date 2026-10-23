# Independent campsite events and managed-link decoration

Release 3.39.1 fixes two distinct sync issues without changing booking-domain occupancy or configuration.

## New campsite reservations

A native campsite reservation is an independent event identified by its MGF reference and occurrence, not by a time interval. Concurrent Google entries do not establish that it is the same reservation. The planner and immediate pre-insert/pre-update checks both use this rule. Each reservation keeps its deterministic Google ID; a newly observed event with the same ID or MGF reference still stops insertion rather than creating a duplicate. No existing estate event is adopted, edited or removed by this rule.

Imported reservations and whole-park/multiday legacy replacements retain the existing provenance reconciliation policy. Standard venue mode retains the existing overlap guard. No availability, whole-venue dependency, payment or email policy is changed. Pending bookings remain excluded unless explicitly configured otherwise.

## Harmless changes on an already managed event

An MGF-created event can have a Google-only location, description, colour or provider version change. A version/hash mismatch previously put it into manual linking review even when its managed schedule was unchanged. The new `refresh_link` operation revalidates the deterministic identity, creation receipt, ownership tags, eligibility and saved projection. It compares title, start/end instants, all-day semantics, status and transparency with the saved managed projection. If these are unchanged, it refreshes the local comparison baseline only, preserving `origin=mgf` and every provider field. It makes one Google GET and no provider mutation. Concurrent source/provider changes fail closed.

If the website schedule also changed, refresh retains the prior projection; the following worker pass performs the existing conditional managed PATCH. Real Google scheduling edits, missing ownership, guests/recurrence, deleted events, missing projections and protected estate-origin links are not accepted as decoration. A reference-only save cannot run the automatic refresh. The GUI distinguishes an existing link from a missing link and counts the refresh as queued work rather than a staff decision.

## Verification

`tests/php-google-calendar-independent.php` extends transport/lifecycle fixtures for separate concurrent campsite events, venue/import preservation, stable-ID race protection, decoration refresh with no Google writes, retained ownership, subsequent managed updates, real scheduling changes and stale-preview rejection. The complete PHP suite and full cross-version integration CI must pass before release. Production rollout must verify both named booking links and preserve all other bookings/settings/calendar history.
