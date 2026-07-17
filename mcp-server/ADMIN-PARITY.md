# MGF Venue admin parity

Version 3.20.0 was audited against every MGF Venue WordPress admin menu page,
all 36 privileged `wp_ajax_mbs_*` hooks, the public booking form operations and
the MCP tool schemas. The static parity smoke test now fails whenever a future
privileged admin AJAX hook is not represented in the closed REST action map.

## Admin surface mapping

| Admin area | MCP/API coverage |
|---|---|
| Dashboard | Structured external/all-booking counters and pending change-request count |
| All Bookings and Archived | List/search/read, external/Scout filtering, status actions, bulk actions, archive past, restore and administrator-only permanent deletion |
| Create booking | Idempotent one-off or weekly creation, conflict/blocked-date validation, Scout-use override, calculated or custom price, pending/confirmed state and opt-in hirer email |
| Booking detail | Edit, notes, invoice, payment link, confirmation/cancellation/payment/deposit/refund transitions |
| Communications | Confirmation/cancellation/payment messages through status actions, payment chase, access details and feedback request |
| Calendar and availability | Calendar/date reads, blocked dates and conflict-aware availability checks |
| Blocked Dates | List, add, remove and clear expired blocks |
| Scout Nights | Scout-only reads, series reads, create, confirm/cancel, edit future occurrences, extend, reopen and administrator-only deletion |
| Change Requests | List, approve and reject with the existing notification behaviour |
| Audit Log | Per-booking and searchable global audit history without stored IP addresses or numeric user IDs |
| Analytics and exports | Existing analytics view plus CSV, Xero, Sage and QuickBooks-compatible accounting exports |
| Settings | Redacted administrator-only configuration read and existing save/test/update handlers |
| Email Templates | Administrator-only read and save/reset payload support through the existing handler |
| Custom Fields | Administrator-only read and save support |
| OSM Integration | Redacted administrator-only settings read, save, connection test and section discovery |
| Home Assistant | Existing feeds and notification classes are unchanged; confirmed creation runs the existing status transition and the existing test webhook remains administrator-only |

## Review findings addressed in 3.20.0

- Normal booking creation was absent even though administrators can use the
  public booking form. A typed REST route and `create_booking` MCP tool now
  support one-off and weekly bookings.
- The global Audit Log page had only per-booking MCP coverage. It now has a
  searchable global read route.
- Dashboard counters and an efficient Scout-only booking filter were missing.
- Multi-day creation transactionally rechecked only the first day and exact
  space. It now locks and checks every day plus related parent/child spaces.
- Capability discovery previously listed administrator-only operations for a
  Booking Manager. It now reports role-appropriate reads and actions.

No unmapped privileged MGF Venue admin AJAX actions remain. WordPress core user
administration, WooCommerce order administration and public hirer account
registration/login are separate product surfaces, not MGF Venue admin pages,
and are intentionally outside this MCP.

## Safety model

- WordPress Application Password authentication is required.
- Booking Manager capability is sufficient for normal booking operations.
- Settings/configuration reads and writes, hard deletion, OSM administration,
  update checks and exports retain their existing administrator checks.
- The generic action name is selected from a hard-coded map. Arbitrary AJAX
  callbacks cannot be invoked.
- Booking creation requires an idempotency key. Retrying the same intended call
  returns the existing booking/series instead of creating a duplicate.
- `notify_hirer` defaults to false. Email is sent only when explicitly enabled.
- Confirmed creation uses the existing status method, preserving Home Assistant
  notifications and the free one-off booking auto-paid rule.
- Stored modification tokens, access codes, GitHub tokens, Home Assistant
  webhook URLs, OSM client IDs and OSM client secrets are not returned.
- Export tools write only to an explicit local `.csv` path.
