# MGF Venue

A comprehensive WordPress venue booking and management plugin built for Needham Market Scout Group, with Home Assistant integration.

> **Note:** This plugin was previously named "Mathlin Booking System". As of v3.14.0 the product is branded **MGF Venue**. Internal identifiers (plugin folder/slug `mathlin-booking`, database tables `wp_mathlin_*`, option keys `mbs_*`, REST namespace `mathlin/v1`, shortcodes `[mathlin_*]`) are unchanged for backward compatibility.

**Current Version:** 3.14.0  
**Requires WordPress:** 5.0+  
**Requires PHP:** 7.4+  
**Tested with WordPress:** 6.7  
**License:** GPL-2.0+

---

## Features

### Public Booking System
- Interactive availability calendar with blocked date indicators
- Booking form with real-time cost calculation (tier-aware)
- Multi-day and full-day booking support
- Recurring weekly bookings (up to 52 weeks)
- Conflict detection prevents double bookings (including parent/child space bundling)
- Configurable minimum notice period
- Custom form fields (admin-configurable)
- Terms & Conditions checkbox
- Public/private event visibility toggle
- Scout Use (free) bookings for volunteers
- Honeypot spam prevention on booking form
- Mobile-optimised responsive design
- Deposit amount shown on booking form when applicable
- Venue info page with configurable booking notice and facilities

### Customer Accounts (Hirer Portal)
- Self-service registration and login (`[mathlin_portal]`)
- Dashboard showing all bookings, invoices, payment status
- Pre-filled booking form for returning hirers
- Quick account creation after first booking
- Booking status lookup (`[mathlin_status]`)
- Modification and cancellation requests

### Admin Dashboard
- Bookings list with search, filter, and CSV export (excludes internal Scout bookings from counters)
- Editable booking details with live cost recalculation
- Custom price override for ad-hoc arrangements
- Calendar view with day-by-day breakdown
- Recurring bookings grouped as collapsible series
- Booking analytics with Chart.js visualisations
- Occupancy/utilisation reports per space
- **Global Audit Log page** with search by reference, action, details or user
- Per-booking audit log on the booking detail view
- Dashboard widget on wp-admin home (Scout bookings excluded from metrics)
- Financial balance indicator (Balance Due / Refund Due) on booking detail
- Mark Balance Paid / Mark Refunded action buttons
- Mark Deposit Paid / Undo Deposit action buttons

### Scout Nights (Recurring Internal Bookings)
- Batch-create recurring weekly Scout section bookings (free, auto-confirmed)
- Dedicated admin page, separate from the public bookings list
- These block public-calendar availability but are excluded from dashboard/booking counters
- **Cancel Series** — cancel all future occurrences (past bookings preserved)
- **Reopen Series** — restore future cancelled occurrences to confirmed
- **Edit Series** — bulk-change time / space / section for all future occurrences (per-date conflict checking, clashes skipped and reported)
- **Extend Series** — add further weekly occurrences up to a new end date (capped at 52 per run)
- **Delete Series** — permanently delete, with a choice of *future only* or *entire series* (admin only)
- Per-row and per-series status display; cancelled rows clearly badged

### Physical Access (Keysafe Codes)
- Automated "Access Details" email sent a configurable number of hours before the event
- Contains the keysafe code and health & safety notes (admin-configurable)
- **Trust-based access gating:** trusted tiers (e.g. Council/Commercial) can receive the code once *confirmed/deposit_paid/paid*; standard public hirers must be fully *paid*
- Access flag reset automatically on cancellation/refund so cancelled bookers can't use a stale code
- Manual "Send Access Details" admin button bypasses all status/tier checks

### Financial
- Configurable spaces and pricing (hourly + daily rates)
- **Tiered pricing** (Standard, Community, Commercial — configurable multipliers)
- **Deposit management** (configurable percentage, balance due window)
- **Payment tracking** via `amount_paid` column driving balance-due logic
- Kitchen add-on pricing
- WooCommerce payment integration (Stripe, PayPal, etc.)
- Deposit-aware WooCommerce checkout (pays deposit or full balance)
- Invoice generation with HTML email attachment
- Automatic payment chasing (3 escalating levels + deposit balance reminders)
- **B2B offline invoicing:** tiers flagged for BACS/PO suppress all "Pay Now" buttons, show configurable bank/PO instructions instead, and receive a gentle statement reminder rather than the escalating chase sequence
- Accounting export (Xero, Sage, QuickBooks CSV)
- Bank transfer details on invoices and modification emails
- Financial year revenue tracking (April–March)

### Modification & Cancellation Requests
- Bookers can request changes via secure link
- Proper approval queue with pending count badge
- Admin approve/reject with one click
- Auto-applies changes on approval (with conflict check)
- **Auto-confirm on approval** (no drop to pending)
- Smart status transition: keeps `paid` if cost decreased, sets `confirmed` if cost increased
- Approval email includes BACS details + Pay Now button when balance due (or BACS/PO block for offline tiers)
- Updated invoice attached to all modification approval emails
- Rejection emails with optional reason

### Email System
- Editable email templates with placeholder tags
- Configurable organisation name, address, logo
- Email queue with automatic retry on failure
- Booking reminders (configurable hours before)
- Multi-admin notifications
- Recurring booking summary emails
- Booking notice preserves line breaks (nl2br)

### Space Bundling (Parent/Child)
- Spaces can be defined as parent or child
- Booking a parent space blocks all child spaces
- Booking a child space blocks the parent space
- Configured via "Parent Space" column in admin settings

### Integrations
- Home Assistant: webhook on confirmation/cancellation + REST API polling
- WooCommerce: Pay Now button in emails, auto-status update, deposit support
- iCal: downloadable .ics files + subscribable calendar feed
- GitHub: auto-update from private repository releases
- OSM (Online Scout Manager): push financial records on payment

### Security & Privacy
- GDPR right-to-erasure (WordPress Privacy tools integration)
- PII anonymisation preserving financial audit trail
- WooCommerce refund detection and status revert (access flag reset)
- IP address logging without trusting proxy headers
- Email queue auto-cleanup of stalled PII-containing entries
- Role-based access control (hirer, booking manager, admin)
- Race-condition-safe booking and batch creation (transactions + row locking)

---

## Installation

1. Upload `mathlin-booking/` to `/wp-content/plugins/`
2. Activate in wp-admin → Plugins
3. Database tables are created automatically (migrations run on version change)

## Shortcodes

| Shortcode | Description |
|---|---|
| `[mathlin_booking]` | Full calendar + booking form |
| `[mathlin_calendar]` | Calendar only (no form, read-only mode) |
| `[mathlin_status]` | Booking status lookup + modification form |
| `[mathlin_manage]` | Unified status/modification page |
| `[mathlin_portal]` | Hirer login/register + dashboard |
| `[mathlin_terms]` | Terms & Conditions page |
| `[mathlin_venue_info]` | Venue information, pricing, facilities |

## REST API

Base: `/wp-json/mathlin/v1/`

| Endpoint | Auth | Description |
|---|---|---|
| `GET /bookings/today` | None | Today's confirmed bookings |
| `GET /bookings/upcoming` | None | Next 30 days |
| `GET /bookings/calendar?year=&month=` | None | Booked dates |
| `GET /bookings/{ref}/ical` | None | iCal download |
| `GET /bookings/ical` | None | iCal feed |
| `GET /bookings` | Admin | All bookings |
| `POST /bookings/{ref}/status` | Admin | Update status |
| `GET /bookings/{ref}/payment-url` | Admin | Generate payment URL |

> Note: keysafe access codes are never exposed in public REST payloads.

---

## Changelog

### v3.14.0
- **Rebrand (Phase 1 — cosmetic):** product renamed from "Mathlin Booking System" to **MGF Venue**. Updated the plugin name/description header, user-profile section heading, WordPress privacy (GDPR) exporter/eraser labels, the updater "View details" name/description, iCal `PRODID`, REST API docblock, and developer log prefixes (`[MGF Venue]`). No internal identifiers changed (plugin slug, DB tables, option keys, cron hooks, AJAX actions, REST namespace, shortcodes, capability) — so existing data, integrations, and the auto-updater are unaffected.

### v3.13.1
- **New:** Manual "Send Feedback Request" button on the admin booking page (mirrors "Send Access Details") — `MBS_Feedback::resend()` ignores the date window and `feedback_sent` flag, trusting the admin; audit-logged as `feedback_sent`.
- **Docs:** Settings note explaining WP-Cron only runs on site traffic, with instructions for a reliable real server cron (`DISABLE_WP_CRON` + crontab hitting `wp-cron.php`).

### v3.13.0
- **New:** Post-Booking Feedback & Review module — emails hirers one day after their booking ends asking for a Google review (`{review_link}`) or private feedback (`{feedback_link}`). Daily WP-Cron (`mbs_daily_feedback`), excludes Scout/internal bookings (`scout_use = 0`), idempotent via the `feedback_sent` column. Configurable master toggle, Google Review URL, subject, WYSIWYG body, and distribution email. Secure frontend form via `[mathlin_feedback]` shortcode (reuses the `modification_token` + `hash_equals()` pattern); submissions are routed to the distribution address.

### v3.12.1
- **Fix:** All Bookings stat cards (Active/Pending/Confirmed/Paid) now exclude internal Scout bookings, matching the rows shown below them
- **Fix:** Removed the leading emoji from the Scout Nights admin menu item for consistency

### v3.12.0
- **New:** Scoped series delete — choose *future only* (preserve past) or *entire series* (past + future)
- **New:** Global Audit Log admin page (Scout Bookings → Audit Log) with search by ref/action/details/user and a row-count selector; surfaces series-level actions logged against `SER-XXXXXX` references
- Added `MBS_Audit_Log::search()` and readable labels for series actions

### v3.11.0
- **Fix:** Cancelled Scout bookings stayed in the list with an active Cancel button (re-cancel reported "none cancelled")
- **New:** Status column on the Scout Nights list; cancelled rows dimmed and badged
- **New:** Reopen Series (restores future cancelled bookings to confirmed) and Delete Series (admin-only hard delete)
- Per-row button flips Cancel ↔ Reopen based on status

### v3.10.0
- **New:** Extend Scout Series — add further weekly occurrences up to a new end date, continuing the existing cadence; capped at 52 per run; conflicting dates skipped and reported

### v3.9.0
- **New:** Bulk Edit Scout Series — change time / space / section for all future occurrences; per-date conflict checking (clashes skipped), cost recalculated per occurrence, HA re-notified

### v3.8.0
- **New:** Bulk Series Cancellation — cancel all future occurrences in one click (past bookings preserved)
- **New:** Dashboard widget metrics exclude internal Scout bookings (`get_stats($exclude_scout)`)

### v3.7.0
- **New:** Tier-Based Offline Invoicing (BACS/PO) — per-tier `offline_invoicing` flag suppresses Pay Now buttons across all emails, injects configurable BACS/PO instructions, and replaces the aggressive chaser with a gentle B2B statement reminder
- **New:** "BACS / Offline Payment Instructions" WYSIWYG setting (Venue & Legal) with `{invoice}`/`{ref}`/`{amount}` placeholders

### v3.6.0
- **New:** Trust-Based Access Gating — per-tier `bypass_access_gate` flag lets trusted tiers receive access codes at confirmed/deposit_paid/paid; standard hirers strictly paid
- **Fix (C-2):** `access_sent` reset to 0 on cancellation and refund, with audit log entry

### v3.5.1
- **Security/Logic audit fixes:** undefined `$time_str` in edit notification (H1), payment URL when no balance (H2), `ajax_undo_deposit` clears `amount_paid` (H3), Scout Nights batch insert wrapped in transaction + row locking (M1), `wp_date()` usage in payment-required and conflict messages (M2/M3)

### v3.4.0
- **New:** Access Details — automated keysafe-code email a configurable number of hours before the event, with health & safety notes; new `access_sent` column

### v3.3.x
- **New:** Mobile-responsive admin panel (bookings table → card layout, responsive stats/filters)
- **Fix:** Balance payments after a deposit no longer misidentified as new deposits

### v3.2.0
- **New:** Payment tracking via `amount_paid` column driving balance-due logic across modifications, refunds and chasing

### v3.0.0 (Major Feature Release)
- **New: Deposit Management** — configurable deposit percentage, balance due window, `deposit_paid` status, deposit-aware checkout, balance chase reminders
- **New: Tiered Pricing** — Standard/Community/Commercial tiers with configurable multipliers, tier-specific per-space rates, user profile tier assignment
- **New: Space Bundling** — parent/child relationships; booking a parent blocks children and vice versa
- New DB columns: `deposit_paid`, `pricing_tier`

### v2.15.x
- TinyMCE save fix; Booking Notice + Facilities fields; full 32-point T&C; Venue & Legal settings; `[mathlin_terms]` / `[mathlin_venue_info]` shortcodes

### v2.13.x–v2.14.x
- Read-only calendar mode; unified `[mathlin_manage]`; WooCommerce UX (login redirect, "My Hall Bookings" tab, manager admin access)

### v2.11.x–v2.12.0
- OSM integration; WooCommerce refund handler; GDPR right-to-erasure; email queue cleanup; performance indexes

### v2.0–v2.10
- Email queue, custom fields, modification requests, analytics, Scout Use, recurring bookings, hirer portal, accounting export, Booking Manager role, bulk actions

### v1.x
- Core booking system, calendar, invoicing, Home Assistant integration, conflict detection, payment chasing, WooCommerce payments, auto-archive, email templates
