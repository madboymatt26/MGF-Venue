# Google Calendar on the booking detail page

Administrators see a card only on sites with calendar configuration. Opening a
booking performs an asynchronous read-only comparison; it never runs the worker.
The card reports current linkage, selected calendar, whether automatic sync is
enabled, live check time, last recorded per-booking action, all linked Google
events (including multiple protected area events), and provider-supplied event links.
Event URLs are restricted to HTTPS Google Calendar paths; no contact details,
Google descriptions, credentials or access codes are returned.

Sync now is available only on an already enabled, reviewed connection, for a
booking whose complete plan contains only matched entries and safe creation or
managed lifecycle operations. Any ambiguous overlap, protected difference, missing
event or other blocked operation sends the operator to Calendar Sync review.
Unapproved/archived/past records are not forcibly exported. Plans above the
existing 20-write worker bound must use the main workflow.

An explicit click/confirmation submits the fresh comparison fingerprint. The
server re-reads the complete calendar/source snapshot under the shared lease,
checks the fingerprint, filters by exact booking reference, and invokes the same
guarded executor with no matching choices. It cannot enable sync, retarget the
calendar, change policy, modify bookings, send hirer mail or run unrelated booking
operations. Existing deterministic event IDs, source revalidation, overlap checks,
ownership receipts and If-Match protections continue to apply. Errors/timeouts
disable Sync now until another read-only check; no automatic write retries.

MCP parity: existing save_settings/calendar_sync accepts booking_preview or
booking_sync with exact ref. booking_sync also requires fingerprint and
confirm=true. No changes to the installed MCP bridge are required.

Google event links use the API's htmlLink field:
https://developers.google.com/workspace/calendar/api/v3/reference/events
