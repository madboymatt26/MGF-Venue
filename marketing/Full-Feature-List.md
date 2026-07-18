# Mathlin Booking System — Full Feature List

*Version 3.12.1*

---

## Public Booking System
- Interactive availability calendar with blocked-date indicators
- Booking form with real-time, tier-aware cost calculation
- Single, multi-day, and full-day (all-day) bookings
- Overnight booking handling (a 22:00→02:00 block counts as one continuous session, not two days)
- Recurring weekly bookings (up to one calendar year inclusive, maximum 53 dates)
- Conflict detection that prevents double bookings, including parent/child space bundling
- Configurable minimum-notice period
- Admin-configurable custom form fields ("Additional Information" section)
- Terms & Conditions acceptance checkbox
- Public/private event visibility toggle
- Scout Use (free) bookings for verified volunteers
- Honeypot spam prevention
- Mobile-optimised responsive design
- Deposit amount shown on the form when applicable
- Configurable booking notice displayed prominently

## Customer Accounts (Hirer Portal)
- Self-service registration and login (`[mathlin_portal]`)
- Dashboard showing all bookings, invoices, and payment status
- Pre-filled booking form for returning hirers
- Quick account creation after a first booking
- Booking status lookup (`[mathlin_status]`)
- Self-service modification and cancellation requests
- Registration rate-limiting (5 per IP per hour)

## Pricing & Tiers
- Configurable bookable spaces with hourly and daily rates
- Kitchen add-on pricing (toggle on/off)
- Tiered pricing (Standard / Community / Commercial, with configurable multipliers)
- Optional explicit per-tier rates per space (`rate_hourly_[tier]`, `rate_daily_[tier]`)
- Per-user tier assignment via WordPress profile
- Custom price override for ad-hoc arrangements
- Four cost calculators kept in sync (PHP source of truth + 3 live JS previews)

## Deposits & Payments
- Deposit management (configurable percentage, configurable balance-due window)
- `deposit_paid` booking status and deposit-aware checkout
- Payment tracking via `amount_paid` (drives balance-due logic everywhere)
- WooCommerce payment integration (Stripe, PayPal, any WC gateway)
- Deposit-aware WooCommerce checkout (charges deposit or remaining balance correctly)
- Mark Balance Paid / Mark Deposit Paid / Undo Deposit / Mark Refunded admin actions
- Auto-promote £0 bookings straight to paid
- Balance/refund indicator on the booking detail (red "Balance Due", green "Refund Due")
- Server-side cart price re-read from the database (anti-tampering)

## Invoicing & Finance
- Automatic invoice generation, attached as HTML to confirmation emails
- Updated invoice attached to all modification-approval emails
- Configurable bank/BACS details and payment terms on invoices
- Financial-year revenue tracking (April–March)
- Accounting export for Xero, Sage, and QuickBooks (CSV)
- CSV export of bookings

## Automated Payment Chasing
- Three-stage escalating reminder sequence (gentle → overdue → urgent)
- Deposit-balance reminders as the event approaches
- Configurable on/off, max chases, and interval between chases
- **B2B offline invoicing**: tiers flagged for BACS/PO suppress all "Pay Now" buttons, inject configurable BACS/PO instructions instead, and receive a single gentle statement reminder rather than the escalating chase

## Physical Access (Keysafe Codes)
- Automated "Access Details" email a configurable number of hours before the event
- Keysafe code plus admin-configurable health & safety notes
- Trust-based access gating: trusted tiers get the code at confirmed/deposit_paid/paid; standard hirers strictly when paid in full
- Access flag auto-reset on cancellation/refund (no stale codes)
- Manual "Send Access Details" admin button that bypasses all checks
- Codes never exposed in public REST payloads

## Scout Nights (Recurring Internal Bookings)
- Batch-create recurring weekly Scout section bookings (free, auto-confirmed)
- Dedicated admin page, separate from the public list
- Block public-calendar availability but excluded from revenue dashboards/counters
- Cancel Series (future only, preserves past)
- Reopen Series (restore future cancelled occurrences)
- Edit Series (bulk change time/space/section for future occurrences, conflicts skipped & reported)
- Extend Series (add weekly occurrences up to a new date, capped at 52 per run)
- Delete Series with choice of future-only or entire series (admin only)
- Per-row and per-series status display; cancelled rows badged

## Admin Dashboard & Management
- Bookings list with search, filter, and status tabs (Scout bookings excluded from counters)
- Editable booking details with live cost recalculation
- Calendar view with day-by-day breakdown
- Recurring bookings grouped as collapsible series
- Booking lifecycle: pending → confirmed → (deposit_paid) → paid → archived, plus cancelled & reopen
- Cancel-with-reason emails
- Bulk actions across multiple bookings
- Booking analytics with Chart.js (occupancy/utilisation per space)
- Global, searchable Audit Log page (by ref/action/details/user) + per-booking audit log
- Internal admin notes per booking
- wp-admin dashboard widget (today/tomorrow/pending, Scout bookings excluded from metrics)
- Auto-archive of past bookings (configurable threshold, daily cron)
- Pending-bookings notification badges on the menu

## Spaces & Conflict Architecture
- Parent/child space bundling (booking the "Whole HQ" locks the "Main Hall" and "Meeting Room", and vice versa)
- Race-condition-safe creation (DB transactions + row locking) on both single and batch bookings

## Blocked Dates
- Full-day or partial (time-range) blocking
- Per-space or all-space blocks
- Automatic cleanup of expired blocks

## Email System
- 15 editable templates with placeholder tags
- Configurable organisation name, address, logo
- Email queue with automatic retry on failure and stalled-entry cleanup
- Booking reminders (configurable hours before)
- Multi-admin notifications + additional notification recipients
- Recurring-booking summary emails
- Line-break-preserving notices (nl2br)

## Modification & Cancellation Requests
- Bookers request changes/cancellations via secure token link
- Approval queue with pending-count badge
- One-click approve/reject; auto-applies on approval with conflict check
- Auto-confirm on approval (no drop to pending)
- Smart status transition (keeps `paid` if cost drops, sets `confirmed` if cost rises)
- Approval emails include BACS details + Pay Now (or BACS/PO block for offline tiers)
- Rejection emails with optional reason

## Venue & Legal
- Configurable Terms & Conditions (full 32-point default), shown in emails and admin
- Configurable venue info, capacity, curfew times, facilities
- Shortcodes: `[mathlin_terms]`, `[mathlin_venue_info]`
- Configurable BACS / Offline Payment Instructions (WYSIWYG)

## Integrations
- **Home Assistant**: real-time webhooks on confirm/cancel + REST polling component; auto heating/lighting before & after bookings; smart gap-detection between back-to-back bookings; occupancy binary sensor; per-booking sensors
- **WooCommerce**: payment, deposit support, "My Hall Bookings" My Account tab, smart login redirects (hirer→portal, manager→admin), manager admin access
- **iCal**: downloadable `.ics` files + subscribable feed
- **OSM (Online Scout Manager)**: push financial records on payment
- **GitHub**: automatic plugin updates from private repository releases

## Shortcodes
- `[mathlin_booking]` — full calendar + booking form
- `[mathlin_calendar]` — calendar only (read-only mode)
- `[mathlin_status]` — status lookup + modification form
- `[mathlin_manage]` — unified status/modification page
- `[mathlin_portal]` — hirer login/register + dashboard
- `[mathlin_terms]` — Terms & Conditions
- `[mathlin_venue_info]` — venue info, pricing, facilities

## REST API (`/wp-json/mathlin/v1/`)
- `GET /bookings/today`, `/bookings/upcoming`, `/bookings/calendar` (public)
- `GET /bookings/{ref}/ical`, `/bookings/ical` (public iCal)
- `GET /bookings`, `POST /bookings/{ref}/status`, `GET /bookings/{ref}/payment-url` (admin)

## Security & Privacy
- GDPR right-to-erasure and data export (WordPress Privacy tools)
- PII anonymisation that preserves the financial audit trail
- Nonce protection on all AJAX; capability-gated (`mbs_manage_bookings` for bookings, `manage_options` for settings/delete)
- Role-based access (hirer, booking manager, administrator)
- Per-booking secure tokens for payment/modification links (`hash_equals`)
- WooCommerce refund detection with status revert and access-flag reset
- IP logging via `REMOTE_ADDR` only (no spoofable proxy headers)
- Timezone-safe throughout (`wp_date()` / `current_time()`, BST/GMT correct)
- Cumulative security hardening (SEC-001→010) and a v3.5.1 logic/security audit pass
