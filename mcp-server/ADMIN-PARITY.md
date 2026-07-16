# MGF Venue admin parity

Version 3.19.0 exposes a closed, authenticated REST action bridge to the same
handlers used by the MGF Venue WordPress admin screens. This avoids duplicating
payment, email, Home Assistant, audit and conflict behaviour.

## Admin surface mapping

| Admin area | MCP/API coverage |
|---|---|
| All Bookings and Archived | List/search/read, status actions, bulk actions, archive past, restore and administrator-only permanent deletion |
| Booking detail | Edit, notes, invoice, payment link, confirmation/cancellation/payment/deposit/refund transitions |
| Communications | Confirmation/cancellation/payment messages through status actions, payment chase, access details and feedback request |
| Calendar and availability | Calendar/date reads, blocked dates and conflict-aware availability checks |
| Blocked Dates | List, add, remove and clear expired blocks |
| Scout Nights | Read, create, confirm/cancel, edit future occurrences, extend, reopen and administrator-only deletion |
| Change Requests | List, approve and reject with the existing notification behaviour |
| Audit Log | Per-booking audit history without stored IP addresses or numeric user IDs |
| Analytics and exports | Booking data reads plus CSV, Xero, Sage and QuickBooks-compatible accounting exports |
| Settings | Redacted configuration read and the existing administrator-only save/test/update handlers |
| Email Templates | Read and administrator-only save/reset payload support through the existing handler |
| Custom Fields | Read and administrator-only save support |
| OSM Integration | Redacted settings read, save, connection test and section discovery |
| Home Assistant | Existing public feeds and notification code are unchanged; the existing test webhook action is exposed administrator-only |

## Safety model

- WordPress Application Password authentication is required.
- Booking Manager capability is sufficient for normal booking operations.
- Existing web-admin capability checks remain authoritative; settings and hard
  deletion still require a WordPress administrator.
- The action name is selected from a hard-coded map. Arbitrary AJAX callbacks
  cannot be invoked.
- Stored modification tokens, access codes, GitHub tokens, Home Assistant
  webhook URLs, OSM client IDs and OSM client secrets are not returned by read
  endpoints.
- The generic MCP action tool is marked destructive so it requires approval.
- Export tools write only to an explicit local `.csv` path.
