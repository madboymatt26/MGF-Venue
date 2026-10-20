# Multi-area campsite bookings (3.33.0)

## Scope and user experience

- Off by default. Settings → Campsite → Multiple area bookings enables it only
  for free campsite mode. Paid venue and internal Scout controls are unchanged.
- Public booking, Add booking, individual amendments and future campsite-series
  edits retain their single-space dropdown and offer an accessible checkbox list
  for several sibling areas. No long list of predefined combinations.
- One reference, contact, approval status and schedule for all selected areas.
  Recurrence creates normal occurrences linked to one series; every occurrence
  has the complete selection. Changing/cancelling the record affects the full set.
- Whole Park / Whole Centre remain normal parent resources. They cannot be mixed
  with children; selecting all children never silently becomes a parent booking.
- Combined attendance uses the **lowest selected capacity**. This is deliberately
  conservative: area capacities cannot be assumed additive for one moving group.
  No new rates, invoices or payment behavior are introduced.

## Storage and compatibility

Physical resources and their existing `parent` settings are not modified. A
selection is a sorted, deduplicated, losslessly delimited set in the `space`
field (`Bomb Hole + Cabin`, separator ` + `). Only configured leaf siblings
qualify. Names containing that separator are ineligible for sets; an exact
configured resource always takes precedence. Ambiguous/colliding or >255-byte
labels are rejected. Booking and series space columns widen from 60 to 255
characters; existing values are unchanged. No display-only group or fake Whole
Park resource is used. Parent dependencies retain their existing semantics.

REST creation accepts `spaces: ["Bomb Hole", "Cabin"]` (alongside the existing
required `space` field), or a canonical string in `space`. Staff frozen previews
canonicalize the same way. Admin booking/series reads expose `selected_spaces`.
The MCP creation schema supports that array; availability uses the same canonical
string. MCP `run_admin_action(save_settings, fields={space_selection:{enabled:true}})`
is a safe partial setting update and does not reset other settings. Configuration
and capabilities expose effective state. Existing bridge installations require
an update/reload for the expanded tool schema; string space input remains usable.

## Occupancy and safety

Every occupied day and continuous-stay interval checks all selected physical
areas. Sets conflict with intersecting sets, their individual members and parent
bookings, not unselected siblings. Blocks on a child or parent apply consistently.
Checks fail closed on database errors. A site-scoped, re-entrant database advisory
lock serializes campsite creation (including whole parents), recurrence, extension,
amendments and restoration; empty dates cannot race between independent writers.
Existing transaction/rollback rules remain in force.

Disabling the option prevents new selections, not historical occupancy. A durable
ever-used marker retains allocation-aware reads/locking even if campsite mode is
also disabled. Existing combined selections stay visible in edit forms. Active
allocations prevent removing/renaming/reparenting their physical areas.

Mail, portal, admin lists, audit, CSV, iCalendar and Google projection consume the
same readable selection label. There is still one record/event identity, not one
booking per area. Protected Google add-only policy is unchanged. Installing this
release does **not** enable the feature, run sync, send mail, consolidate existing
reservations or modify Needham settings.

## Existing May reservations

The five-area 28–31 May event can eventually use this model while Paddock remains
its separate 25–31 May booking. No live consolidation is included in this change.
It requires a separately reviewed, fingerprinted conversion with recoverable
sources, preservation of ownership/status/exact dates, and a fresh Google-link
check. Do not repurpose the six-source Whole Park-only migration endpoint.
