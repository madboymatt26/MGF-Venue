# Managed MGF-created calendar lifecycle

## Scope and configuration

Per-installation opt-in: Calendar Sync → Connection settings → Changes and cancellations.
Default remains off for upgrades. Saving pauses sync and requires a fresh comparison
before enabling. MCP save_settings/calendar_sync operation managed_policy accepts a
strict boolean managed_updates, fresh fingerprint and confirm=true, then pauses.
The existing preview and enable flow follows. No other settings are changed.

Estate-origin references (including many-area and shared date slices) remain read-only.
Unresolved imports never create new events. This is one-way booking → Google sync,
not permission to import Google changes or to amend estate-owned events.

## Eligibility and mutations

An update requires a local origin=mgf creation receipt, one event only, the deterministic
Google ID derived from the original creation key, and exact site/booking/key ownership
tags. Guests, recurring Google events, deletion, missing receipts or mismatched tags
block mutation. The last saved full event hash (including ETag) must match the live
GET and preview. Independent Google edits require review; they are never silently
overwritten. Only public summary, start/end, status/transparency and necessary MGF
ownership tags are patched. Existing colour, description, location, reminders and
other private/shared tags are preserved. No attendees or access/contact details exported.

The single PATCH path is a dedicated guarded worker, not the generic API transport.
Fixed selected calendar/event URL, no redirects, If-Match ETag and sendUpdates=none.
Booking source is rechecked immediately before dispatch. Update destination intervals
are re-read to block new estate overlaps. HTTP 412, timeouts or incomplete receipts
do not advance the saved baseline; refresh and inspect, never retry blindly.

## Cancellation, restoration and changing dates

Explicit MGF cancellations become 'Cancelled — …' and transparent (non-blocking),
retaining original dates/history. Withdrawn approval becomes 'Not confirmed — …'.
Removed future projected slices become 'Superseded — …'. Missing sources, archives
and past history do not authorise cancellation. All cases use PATCH, never DELETE.
Reapproval/restoration updates the same owned event back to its active title and busy
time, subject to current availability and Google baseline checks.

Unambiguous single-occurrence date moves keep the same Google ID, rekey the local
reference and ownership booking key, and preserve the original ID seed. Multi-slice
range changes retire obsolete owned slices before adding replacements. Native future
occurrences use the existing five-minute, bounded worker; pending export remains a
separate option. Known inactive owned entries do not block their replacement slots.

Saving references only never performs lifecycle writes. Release/deployment itself
does not activate this policy or change bookings. Needham settings/data remain separate.

## Provider references

- Conditional writes: https://developers.google.com/calendar/api/guides/version-resources
- PATCH fields and sendUpdates: https://developers.google.com/workspace/calendar/api/v3/reference/events/patch
- Transparency semantics: https://developers.google.com/workspace/calendar/api/v3/reference/events/update
