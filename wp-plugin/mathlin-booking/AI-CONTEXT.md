# AI-CONTEXT.md — Mathlin Booking System (v3.12.1)

This document is designed for LLMs and AI agents to read before modifying this codebase. It maps the architecture, file relationships, and critical business logic rules.

---

## Architecture Overview

WordPress plugin using custom database tables (not custom post types). No external frameworks — vanilla PHP, jQuery, and Chart.js.

### Database Tables (MariaDB)

| Table | Purpose |
|---|---|
| `wp_mathlin_bookings` | All booking records |
| `wp_mathlin_blocked_dates` | Admin-blocked date ranges |
| `wp_mathlin_audit_log` | Action history per booking |
| `wp_mathlin_email_queue` | Failed emails queued for retry |
| `wp_mathlin_mod_requests` | Modification/cancellation requests |

### WordPress Options (wp_options)

All prefixed `mbs_`. Key ones:
- `mbs_spaces` (JSON) — space config with rates, capacity, parent
- `mbs_kitchen_price`, `mbs_admin_email`, `mbs_min_notice_days`
- `mbs_ha_webhook_url`, `mbs_org_name`, `mbs_org_logo_url`
- `mbs_scout_volunteer_emails`, `mbs_github_token`
- `mbs_deposit_enabled`, `mbs_deposit_percentage`, `mbs_deposit_balance_days`
- `mbs_pricing_tiers` (JSON) — tier definitions: `{key: {label, multiplier, bypass_access_gate, offline_invoicing}}`
- `mbs_venue_capacity`, `mbs_curfew_saturday`, `mbs_curfew_sunday`
- `mbs_booking_notice`, `mbs_facilities_text`, `mbs_terms_text`
- `mbs_offline_payment_instructions` — BACS/PO instructions for offline (B2B) tiers; supports `{invoice}`/`{ref}`/`{amount}`
- `mbs_access_enabled`, `mbs_access_code`, `mbs_access_instructions`, `mbs_access_hours_before`, `mbs_access_health_safety`
- `mbs_auto_chase_enabled`, `mbs_auto_archive_days`, `mbs_reminder_hours`

### User Meta

- `mbs_pricing_tier` — assigned pricing tier (standard, community, commercial, etc.)
- `mbs_scout_volunteer` — flag for scout volunteer status

---

## File Structure

```
mathlin-booking/
├── mathlin-booking.php              Main plugin file, bootstraps everything + user profile hooks
├── uninstall.php                    Cleanup on deletion
├── README.md                        User documentation
├── AI-CONTEXT.md                    This file
│
├── includes/
│   ├── class-database.php           Table creation + migrations
│   ├── class-bookings.php           ★ CRUD + pricing engine + deposits + tiers + space bundling
│   ├── class-email.php              All email sending (uses templates) + invoice attachment helper
│   ├── class-email-templates.php    Template storage + placeholder replacement
│   ├── class-email-queue.php        Retry queue for failed emails
│   ├── class-invoice.php            Invoice HTML generation
│   ├── class-rest-api.php           REST API endpoints
│   ├── class-homeassistant.php      HA webhook + data formatting
│   ├── class-blocked-dates.php      Blocked date management
│   ├── class-reminders.php          WP-Cron booking reminders
│   ├── class-access-details.php     ★ WP-Cron keysafe access-code emails (trust-based gating)
│   ├── class-payment-chaser.php     Overdue payment auto-chase + deposit balance reminders + B2B statement reminder
│   ├── class-auto-archive.php       WP-Cron auto-archive past bookings
│   ├── class-csv-export.php         CSV download handler
│   ├── class-dashboard-widget.php   wp-admin home widget
│   ├── class-audit-log.php          Action logging
│   ├── class-custom-fields.php      Admin-configurable form questions
│   ├── class-modification.php       ★ Modification request system + approval + auto-confirm
│   ├── class-hirer-portal.php       Customer accounts + dashboard
│   ├── class-woo-payment.php        ★ WooCommerce payment (deposit-aware)
│   ├── class-accounting-export.php  Xero/Sage/QuickBooks CSV
│   ├── class-ical.php               iCal file generation
│   ├── class-updater.php            GitHub release auto-updater
│   ├── class-osm-integration.php    Online Scout Manager integration
│   └── class-woo-ux.php             WooCommerce UX for hirers + managers
│
├── admin/
│   ├── class-admin.php              ★ Admin menu, AJAX handlers, edit booking, mark refunded
│   ├── admin.js                     Admin JavaScript (all AJAX calls, tier/space management)
│   ├── admin.css                    Admin styles
│   └── views/
│       ├── list.php                 Bookings list (with series grouping)
│       ├── single.php               ★ Booking detail + edit mode + balance indicator + cost calc JS
│       ├── invoice.php              Invoice display/print
│       ├── calendar.php             Admin calendar view
│       ├── settings.php             Plugin settings (spaces, tiers, deposits, venue, rules)
│       ├── email-templates.php      Email template editor
│       ├── analytics.php            Charts + reports
│       ├── archived.php             Archived bookings
│       ├── blocked.php              Blocked dates management
│       ├── custom-fields.php        Custom field editor
│       ├── scout-nights.php         ★ Scout Nights: batch create + series cancel/reopen/edit/extend/delete
│       ├── audit-log.php            Global audit log page (search + recent activity)
│       └── requests.php             Modification request queue
│
└── public/
    ├── class-public.php             ★ Shortcodes, AJAX handlers, validation, tier/deposit data
    ├── public.js                    ★ Calendar, cost calculator (tier-aware), form logic
    ├── public.css                   Frontend styles
    └── views/
        ├── booking-form.php         Full booking form (with booking notice)
        ├── calendar.php             Calendar shortcode
        ├── booking-status.php       Status lookup page
        ├── modification-form.php    ★ Change request form + cost calc JS
        ├── hirer-login.php          Login/register page
        └── hirer-dashboard.php      Hirer portal dashboard
```

Files marked ★ are the most critical and most frequently modified.

---

## Cost Calculation Engine

### CRITICAL: There are 4 independent cost calculators that MUST stay in sync

| Location | Language | Function |
|---|---|---|
| `includes/class-bookings.php` | PHP | `calculate_cost()` — **source of truth** |
| `public/public.js` | JS | `updateCost()` — booking form preview |
| `admin/views/single.php` | JS | `recalcEditCost()` — admin edit preview |
| `public/views/modification-form.php` | JS | `recalcModCost()` — modification preview |

**Any change to pricing logic MUST be applied to all 4 locations.**

### Pricing Rules (v3.0.0 — Tier-Aware)

```
if (scout_use) → cost = 0 (free)

// Determine rate based on tier
tier = user's assigned tier (default: 'standard')
multiplier = tier multiplier (e.g. 1.0, 0.75, 1.5)

// Check for tier-specific rate first (e.g. rate_hourly_community)
// If not set, apply multiplier to standard rate

if (all_day):
    rate = rate_daily_[tier] OR (rate_daily × multiplier)
    cost = rate × num_days

if (hourly):
    rate = rate_hourly_[tier] OR (rate_hourly × multiplier)
    hours = ceil((end_time - start_time) / 3600)
    
    if (end_time <= start_time):  // overnight booking
        hours = ceil((end_time + 24hrs - start_time) / 3600)
    
    effective_days = num_days
    if (overnight AND num_days == 2):
        effective_days = 1  // single continuous block, not 2 separate days
    
    cost = hours × rate × effective_days

cost += kitchen_price (if kitchen selected)
cost = round(cost, 2)
```

### The Overnight Rule (CRITICAL)

A booking from 22:00 May 25 to 02:00 May 26 spans 2 calendar dates but is ONE continuous 4-hour block. The date calculation gives `num_days = 2`, but the overnight detection sets `effective_days = 1`.

This rule ONLY applies when `num_days == 2` AND the booking is overnight. A 3+ day overnight booking (e.g., 22:00 Mon to 02:00 Thu) correctly uses `num_days = 4`.

### num_days Calculation

```
num_days = max(1, round((end_date - start_date) / 86400) + 1)
```

For single-day bookings, `end_date` equals `start_date`, so `num_days = 1`.

---

## Deposit Management

### Configuration (wp_options)
- `mbs_deposit_enabled` — 0 or 1
- `mbs_deposit_percentage` — default 25 (percent of total)
- `mbs_deposit_balance_days` — default 7 (days before event when balance is due)

### Logic
```
if deposit_enabled:
    if days_until_event > balance_days:
        payment_amount = total × (percentage / 100)   // deposit only
        on_payment → status = 'deposit_paid', deposit_paid = amount
    else:
        payment_amount = total                         // full payment required
        on_payment → status = 'paid'

if status == 'deposit_paid':
    payment_amount = total - deposit_paid              // remaining balance
    on_payment → status = 'paid'
```

### Payment Chaser Integration
The payment chaser queries `deposit_paid` bookings where `booking_date <= today + balance_days`, sending balance reminders with pay links. Balance owed is derived from `amount - amount_paid`.

**B2B exemption:** if `MBS_Bookings::booking_is_offline($booking)` is true (the tier has `offline_invoicing` enabled), `send_chase()` returns early and calls `send_b2b_statement_reminder()` instead — a single calm reminder for the hirer's finance department, with no escalating "pay now or cancel" sequence.

---

## Tiered Pricing

### Configuration
- `mbs_pricing_tiers` option stores tier definitions:
  ```json
  {
    "standard":   {"label": "Standard", "multiplier": 1.0, "bypass_access_gate": false, "offline_invoicing": false},
    "community":  {"label": "Charity / Community", "multiplier": 0.75},
    "commercial": {"label": "Commercial", "multiplier": 1.5, "bypass_access_gate": true, "offline_invoicing": true}
  }
  ```
- User meta `mbs_pricing_tier` stores the assigned tier per user
- Spaces can optionally define `rate_hourly_[tier_key]` and `rate_daily_[tier_key]` for explicit tier rates

### Per-tier flags
- `bypass_access_gate` — trusted tiers receive their keysafe code once *confirmed/deposit_paid/paid* rather than strictly *paid* (see Access Details)
- `offline_invoicing` — B2B (BACS/PO) tiers: suppresses all WooCommerce "Pay Now" buttons, injects `mbs_offline_payment_instructions` into emails, and routes the payment chaser to a gentle statement reminder instead of the escalating chase. Helpers: `MBS_Bookings::tier_is_offline()` / `booking_is_offline()`.

### Resolution Order
1. Check for tier-specific rate on the space (e.g. `rate_hourly_community`)
2. If not set, apply tier multiplier to the standard rate
3. Guests (not logged in) always get `standard` tier

### Admin Assignment
- WordPress user profile page shows "Pricing Tier" dropdown (admin-only)
- Stored as `mbs_pricing_tier` user meta

---

## Space Bundling (Parent/Child)

### Configuration
Each space in `mbs_spaces` can have a `parent` field:
```json
{
  "Whole Headquarters": {"rate_hourly": 50, "rate_daily": 300, "parent": null},
  "Main Scout Hall": {"rate_hourly": 25, "rate_daily": 150, "parent": "Whole Headquarters"},
  "Meeting Room": {"rate_hourly": 12, "rate_daily": 70, "parent": "Whole Headquarters"}
}
```

### Conflict Detection
`check_conflicts()` calls `get_related_spaces()` which returns:
- If booking a **child** → returns the parent space name
- If booking a **parent** → returns all child space names

Conflicts are checked against the requested space AND all related spaces.

---

## Scout Nights (Recurring Internal Bookings)

Free, auto-confirmed recurring bookings for Scout sections. Flagged by `scout_use = 1` and grouped by `series_id` (`SER-XXXXXX`). Managed on the dedicated **Scout Nights** admin page (`admin/views/scout-nights.php`), kept separate from the public bookings list.

### Key rules
- Scout bookings are created with `status = 'confirmed'` and `amount = 0`.
- They **block public-calendar availability** but are **excluded from dashboard/booking counters** — pass `$exclude_scout = true` to `MBS_Bookings::get_stats()`.
- The All Bookings list query uses `exclude_scout => true`.

### Series operations (all in `class-bookings.php`, AJAX in `class-admin.php`)

| Method | AJAX action | Scope | Notes |
|---|---|---|---|
| `cancel_series_future()` | `mbs_cancel_scout_series` | future only (`booking_date >= today`) | preserves past; HA cancel notices |
| `reopen_series_future()` | `mbs_reopen_scout_series` | future cancelled → confirmed | no emails; HA re-notify |
| `update_series_future($fields)` | `mbs_edit_scout_series` | future only | per-date conflict check (clashes skipped/reported), cost recalculated, HA re-notify |
| `extend_series($new_end)` | `mbs_extend_scout_series` | appends weekly occurrences | template = latest booking; **capped at 52 per run**; conflict/blocked check with row locking |
| `delete_series($scope)` | `mbs_delete_scout_series` | `'future'` or `'all'` | **admin-only** (`can_delete_bookings()`); hard delete; HA cancel for active future |

The "future only, preserve the past" philosophy is deliberate. Only `delete_series('all')` removes past rows.

---

## Access Details (Keysafe Codes)

File: `includes/class-access-details.php`. WP-Cron job emails the keysafe code a configurable number of hours before the event.

### Trust-based gating (`is_eligible_for_access()`)
- Tiers with `bypass_access_gate` enabled → eligible at `confirmed`, `deposit_paid` or `paid`.
- Standard tiers → strictly `paid` (100% settled).
- `access_sent` column prevents duplicate sends; it is **reset to 0** on cancellation/refund (`on_order_refunded`, `update_status`) so a cancelled booker can't reuse a stale code.
- The manual admin "Send Access Details" button (`MBS_Access_Details::resend()`) bypasses all status/tier checks.
- Keysafe codes are **never** exposed in public REST payloads.

---

## Booking Status Flow

```
pending → confirmed → paid → archived
              ↓           ↗
         deposit_paid ──┘
              ↓
         cancelled → archived
              ↗ (reopen)
```

Valid statuses: `pending`, `confirmed`, `deposit_paid`, `cancelled`, `archived`, `paid`

---

## Modification Approval Flow (v2.15.3+)

When an admin approves a modification:
1. Changes are applied to the booking (with conflict check)
2. Cost is recalculated
3. **Status is set automatically** (no drop to pending):
   - If previously `paid` and new cost ≤ old cost → stays `paid`
   - If new cost > paid amount → set to `confirmed`
4. Approval email sent with:
   - BACS payment details (if balance due)
   - Pay Now button (if WooCommerce available and balance due)
   - Updated invoice attached (always)
5. Audit log records the transition

---

## Admin Balance Indicator (single.php)

On the booking detail page, if a booking has been paid (via WooCommerce or manually) and the current amount differs from what was paid:
- **Red alert:** "⚠️ Balance Due: £X" + "Mark Balance Paid" button
- **Green alert:** "💰 Refund / Credit Due: £X" + "Mark Refunded" button

Payment detection:
1. Query WooCommerce orders by `_mbs_booking_ref` meta
2. Sum order totals minus refunds
3. If no WooCommerce orders but status is `paid`, assume full amount was paid (bank transfer)

"Mark Balance Paid" → sets status to `paid` + sends payment confirmation email
"Mark Refunded" → sets status to `paid` silently (no email) + audit log entry

---

## Scout Use Validation

The `scout_use` flag is validated server-side in `class-bookings.php create()`:
1. Check if `$_POST['scout_use']` is set
2. Load `mbs_scout_volunteer_emails` option (newline-separated)
3. Compare submitter's email (case-insensitive) against the list
4. Only set `scout_use = true` if email matches

The frontend dropdown is only shown to logged-in users whose email is in the volunteer list.

Admins can override scout_use when editing a booking (no email check — intentional).

---

## Security Model

- **Nonces:** All AJAX calls use WordPress nonces (`mbs_admin_nonce` for admin, `mbs_public_nonce` for public)
- **Capability checks:** Admin AJAX handlers check `mbs_manage_bookings` (Booking Managers + Admins). Settings/config handlers check `manage_options` (Admins only). Delete checks `manage_options` (Admins only).
- **Roles:** `mbs_hirer` (public bookers), `mbs_booking_manager` (volunteers), `administrator` (full access)
- **Payment tokens:** Payment and modification URLs use `modification_token` (per-booking, session-independent) verified with `hash_equals()`
- **Race conditions:** Booking creation uses `START TRANSACTION` / `COMMIT` with conflict re-check inside the transaction
- **Rate limiting:** Hirer registration limited to 5 per IP per hour via transients
- **Honeypot:** Hidden form field `mbs_website_url` — if filled (by bots), submission silently returns fake success
- **WooCommerce price:** Always re-read from database in `set_cart_item_price()`, never from cart session (deposit-aware)
- **Booking lookup:** Requires both reference AND email to prevent enumeration
- **Timezone:** All date calculations use `wp_date()` / `current_time()` to respect WordPress timezone settings (BST/GMT safe)

---

## Security Patches (v2.12.0)

| ID | Fix | File |
|---|---|---|
| SEC-001 | WooCommerce refund reverts paid→confirmed | class-woo-payment.php |
| SEC-002 | GDPR right-to-erasure (WordPress Privacy Eraser) | class-bookings.php |
| SEC-003 | Email queue force-fails stalled entries >7 days | class-email-queue.php |
| SEC-004 | link_existing_bookings checks mbs_hirer role | class-hirer-portal.php |
| SEC-005 | Payment for cancelled booking logs critical warning | class-woo-payment.php |
| SEC-007 | idx_email index on bookings table | class-database.php |
| SEC-009 | idx_chase composite index for payment chaser | class-database.php |
| SEC-010 | Audit log uses REMOTE_ADDR only (no X-Forwarded-For) | class-audit-log.php |

### Security / Logic Audit Fixes (v3.5.1)

| ID | Fix | Area |
|---|---|---|
| H1 | Undefined `$time_str` in `send_edit_notification` | class-admin.php |
| H2 | `generate_payment_url` returns empty when no balance owed | class-woo-payment.php |
| H3 | `ajax_undo_deposit` clears `amount_paid` alongside `deposit_paid` | class-admin.php |
| M1 | Scout Nights batch insert wrapped in transaction + `FOR UPDATE` row lock | class-admin.php |
| M2 | `requires_full_payment` uses `wp_date()` | class-bookings.php |
| M3 | `format_conflict_message` uses `wp_date()` | class-bookings.php |

---

## Email System

15 template types stored in `wp_options` as `mbs_email_template_{type}`. Each has a subject and body with placeholder tags.

Types: `booking_received`, `admin_notification`, `booking_confirmed`, `booking_cancelled`, `payment_received`, `booking_reminder`, `access_details`, `chase_gentle`, `chase_overdue`, `chase_urgent`, `booking_edited`, `recurring_summary`, `modification_approved`, `modification_rejected`, `admin_mod_request`.

Placeholders: `{name}`, `{ref}`, `{space}`, `{date}`, `{time}`, `{amount}`, `{invoice}`, `{admin_email}`, `{phone}`, `{org_name}`, `{org_address}`, `{charity_number}`, `{bank_details}`, `{pay_url}`, `{reason}`, `{organisation}`, `{attendees}`, `{purpose}`

All emails route through `MBS_Email_Queue::send()` which wraps `wp_mail()` with automatic retry on failure.

### Invoice Attachment
`MBS_Email::generate_invoice_attachment_for($booking)` — public helper that generates an HTML invoice file and returns the file path array for wp_mail attachments. Used by confirmation emails and modification approval emails.

---

## WP-Cron Jobs

| Hook | Schedule | Class | Purpose |
|---|---|---|---|
| `mbs_daily_reminders` | Daily 7am | MBS_Reminders | Booking reminder emails |
| `mbs_daily_access_details` | Daily 8am | MBS_Access_Details | Keysafe access-code emails (trust-based gating) |
| `mbs_daily_payment_chase` | Daily 9am | MBS_Payment_Chaser | Overdue payment + deposit balance reminders + B2B statement reminders |
| `mbs_daily_auto_archive` | Daily 2am | MBS_Auto_Archive | Archive past bookings |
| `mbs_process_email_queue` | Hourly | MBS_Email_Queue | Retry failed emails |

---

## Home Assistant Integration

Separate codebase in `ha-integration/custom_components/mathlin_booking/`.

- Polls `/wp-json/mathlin/v1/bookings/today` at midnight
- Creates sensors per booking
- Fires `mathlin_booking_start` and `mathlin_booking_end` events
- Smart gap detection: skips shutdown if next booking within N minutes
- Binary occupancy sensor: ON during active bookings

---

## WooCommerce Payment (v3.0.0 — Deposit-Aware)

File: `includes/class-woo-payment.php`

### Payment Flow
1. `generate_payment_url($booking)` creates a checkout URL with ref + token
2. `handle_payment_redirect()` determines payment amount:
   - If `deposit_paid` status → charges remaining balance
   - If `confirmed` + deposit enabled + event far away → charges deposit only
   - Otherwise → charges full amount
3. `set_cart_item_price()` re-reads from DB (deposit-aware, prevents tampering)
4. `on_order_completed()` detects payment type:
   - If amount < 90% of total and deposit enabled → sets `deposit_paid` status
   - Otherwise → sets `paid` status

### Allowed Statuses for Payment
`confirmed` and `deposit_paid` (expanded from just `confirmed`)

---

## WooCommerce UX Integration (v2.13.x)

File: `includes/class-woo-ux.php`

### Login Redirect Logic
- `mbs_hirer` role → Frontend Bookings Portal page
- `mbs_manage_bookings` capability → `wp-admin/admin.php?page=mathlin-booking`
- All other roles → Default WordPress/WooCommerce behaviour

### My Account Menu
- Adds "My Hall Bookings" tab for both hirers AND booking managers
- Removes Downloads/Addresses tabs for pure hirers only
- Endpoint: `hall-bookings`

---

## Home Assistant Data Flow

```
┌─────────────────────────────┐         ┌──────────────────────────────────────┐
│     WordPress Plugin        │         │         Home Assistant                │
│                             │         │                                      │
│  PUSH (real-time):          │         │  custom_components/mathlin_booking/  │
│  On confirm → POST webhook  │──PUSH──►│  • Webhook trigger automations       │
│  On cancel  → POST webhook  │         │  • Instant notifications             │
│                             │         │                                      │
│  PULL (scheduled):          │         │  Coordinator (midnight poll):        │
│  GET /bookings/today        │◄─POLL───│  • Fetches today's bookings          │
│  GET /bookings/upcoming     │         │  • Schedules internal timers         │
│                             │         │  • Fires events before/after         │
│                             │         │                                      │
│                             │         │  Creates:                            │
│                             │         │  • binary_sensor.hall_occupied        │
│                             │         │  • sensor.hall_bookings_today         │
│                             │         │  • mathlin_booking_start event        │
│                             │         │  • mathlin_booking_end event          │
└─────────────────────────────┘         └──────────────────────────────────────┘
```

---

## Key Conventions

- **PHP class prefix:** `MBS_` (Mathlin Booking System)
- **CSS class prefix:** `nms-` (legacy from original naming, kept for consistency)
- **AJAX action prefix:** `mbs_`
- **Option prefix:** `mbs_`
- **Database table prefix:** `mathlin_`
- **Booking reference format:** `MBS-XXXXXX` (base36)
- **Invoice format:** `INV-MBS-XXXXXX`
- **Series format:** `SER-XXXXXX`
- **Date functions:** ALWAYS use `wp_date()` instead of `date()` for "today" calculations. This ensures BST/GMT correctness.
- **Capability for booking management:** `mbs_manage_bookings` (not `manage_options`)
- **Capability for settings/delete:** `manage_options` (admin only)
- **Pricing tier default:** `standard` (1.0× multiplier)

---

## Common Modification Patterns

### Adding a new setting
1. Add default in the relevant `get_*()` method
2. Add field to `admin/views/settings.php`
3. Add to `admin/admin.js` save handler (in the `$.post` data object)
4. Add to `admin/class-admin.php` `ajax_save_settings()`

### Adding a new email type
1. Add template definition in `class-email-templates.php` `get_template_types()`
2. Add send method in `class-email.php`
3. Call it from the appropriate trigger point
4. Template automatically appears in Email Templates admin page

### Adding a new admin page
1. Add `add_submenu_page()` in `class-admin.php` `add_menu()`
2. Add render method
3. Create view file in `admin/views/`
4. Add any AJAX handlers with `add_action('wp_ajax_mbs_...')`

### Adding a new database column
1. Add to CREATE TABLE in `class-database.php`
2. Add migration in `maybe_run_migrations()`
3. Update `create()` in `class-bookings.php` to include in insert
4. Update any relevant views/forms

### Adding a new pricing tier
1. Go to Scout Bookings → Settings → Pricing Tiers
2. Click "+ Add Tier", enter key (lowercase, no spaces), label, and multiplier
3. Save settings
4. Assign to users via their WordPress profile page
5. Optionally add tier-specific rates to spaces (e.g. `rate_hourly_community`)

### Adding a new space with parent/child bundling
1. Go to Scout Bookings → Settings → Bookable Spaces
2. Add the parent space (leave Parent Space blank)
3. Add child spaces with the parent's name in the "Parent Space" column
4. Conflict detection will automatically block related spaces

---

## Database Schema (v3.12.1)

### wp_mathlin_bookings
| Column | Type | Notes |
|---|---|---|
| id | BIGINT AUTO_INCREMENT | Primary key |
| ref | VARCHAR(20) UNIQUE | Booking reference (MBS-XXXXXX) |
| status | VARCHAR(20) | pending/confirmed/deposit_paid/paid/cancelled/archived |
| name | VARCHAR(100) | Booker name |
| organisation | VARCHAR(100) | |
| email | VARCHAR(150) | |
| phone | VARCHAR(30) | |
| address | TEXT | Billing address |
| space | VARCHAR(60) | Space name |
| kitchen | TINYINT(1) | Kitchen add-on |
| booking_date | DATE | Start date |
| booking_date_end | DATE | End date (multi-day) |
| all_day | TINYINT(1) | Full day booking |
| scout_use | TINYINT(1) | Free scout booking |
| pricing_tier | VARCHAR(30) | Tier applied at booking time |
| start_time | TIME | |
| end_time | TIME | |
| attendees | SMALLINT | |
| purpose | VARCHAR(255) | |
| notes | TEXT | Booker notes |
| amount | DECIMAL(8,2) | Total cost |
| deposit_paid | DECIMAL(8,2) | Amount paid as deposit |
| amount_paid | DECIMAL(8,2) | Total payments received (drives balance_due) — v3.2.0 |
| invoice_number | VARCHAR(30) | INV-MBS-XXXXXX |
| ha_notified | TINYINT(1) | HA webhook sent |
| reminder_sent | TINYINT(1) | Reminder email sent |
| access_sent | TINYINT(1) | Keysafe access email sent — v3.4.0; reset on cancel/refund |
| chase_count | SMALLINT | Payment chase count |
| last_chased | DATETIME | Last chase email sent |
| series_id | VARCHAR(20) | Recurring series ID (SER-XXXXXX) |
| admin_notes | TEXT | Internal admin notes |
| custom_fields | TEXT | JSON custom field responses |
| modification_token | VARCHAR(64) | Secure token for payment/mod links |
| is_public | TINYINT(1) | Public event visibility |
| user_id | BIGINT | WordPress user ID (hirer portal) |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-updated |

> Migrations are additive and idempotent in `MBS_Database::maybe_run_migrations()` — each new column is guarded by a `SHOW COLUMNS LIKE` check, so they run safely on existing installs when `MBS_VERSION` changes.
