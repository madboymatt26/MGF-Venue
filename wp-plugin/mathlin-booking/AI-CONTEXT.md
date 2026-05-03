# AI-CONTEXT.md — Mathlin Booking System

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

All prefixed `mbs_`. Key ones: `mbs_spaces` (JSON), `mbs_kitchen_price`, `mbs_admin_email`, `mbs_min_notice_days`, `mbs_ha_webhook_url`, `mbs_org_name`, `mbs_org_logo_url`, `mbs_scout_volunteer_emails`, `mbs_github_token`.

---

## File Structure

```
mathlin-booking/
├── mathlin-booking.php              Main plugin file, bootstraps everything
├── uninstall.php                    Cleanup on deletion
├── README.md                        User documentation
├── AI-CONTEXT.md                    This file
│
├── includes/
│   ├── class-database.php           Table creation + migrations
│   ├── class-bookings.php           ★ CRUD + pricing engine (calculate_cost)
│   ├── class-email.php              All email sending (uses templates)
│   ├── class-email-templates.php    Template storage + placeholder replacement
│   ├── class-email-queue.php        Retry queue for failed emails
│   ├── class-invoice.php            Invoice HTML generation
│   ├── class-rest-api.php           REST API endpoints
│   ├── class-homeassistant.php      HA webhook + data formatting
│   ├── class-blocked-dates.php      Blocked date management
│   ├── class-reminders.php          WP-Cron booking reminders
│   ├── class-payment-chaser.php     Overdue payment auto-chase
│   ├── class-auto-archive.php       WP-Cron auto-archive past bookings
│   ├── class-csv-export.php         CSV download handler
│   ├── class-dashboard-widget.php   wp-admin home widget
│   ├── class-audit-log.php          Action logging
│   ├── class-custom-fields.php      Admin-configurable form questions
│   ├── class-modification.php       ★ Modification request system + approval
│   ├── class-hirer-portal.php       Customer accounts + dashboard
│   ├── class-woo-payment.php        WooCommerce payment integration
│   ├── class-accounting-export.php  Xero/Sage/QuickBooks CSV
│   ├── class-ical.php               iCal file generation
│   └── class-updater.php            GitHub release auto-updater
│
├── admin/
│   ├── class-admin.php              ★ Admin menu, AJAX handlers, edit booking
│   ├── admin.js                     Admin JavaScript (all AJAX calls)
│   ├── admin.css                    Admin styles
│   └── views/
│       ├── list.php                 Bookings list (with series grouping)
│       ├── single.php               ★ Booking detail + edit mode + cost calc JS
│       ├── invoice.php              Invoice display/print
│       ├── calendar.php             Admin calendar view
│       ├── settings.php             Plugin settings
│       ├── email-templates.php      Email template editor
│       ├── analytics.php            Charts + reports
│       ├── archived.php             Archived bookings
│       ├── blocked.php              Blocked dates management
│       ├── custom-fields.php        Custom field editor
│       └── requests.php             Modification request queue
│
└── public/
    ├── class-public.php             ★ Shortcodes, AJAX handlers, validation
    ├── public.js                    ★ Calendar, cost calculator, form logic
    ├── public.css                   Frontend styles
    └── views/
        ├── booking-form.php         Full booking form
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

### Pricing Rules

```
if (scout_use) → cost = 0 (free)

if (all_day):
    cost = rate_daily × num_days

if (hourly):
    hours = ceil((end_time - start_time) / 3600)
    
    if (end_time <= start_time):  // overnight booking
        hours = ceil((end_time + 24hrs - start_time) / 3600)
    
    effective_days = num_days
    if (overnight AND num_days == 2):
        effective_days = 1  // single continuous block, not 2 separate days
    
    cost = hours × rate_hourly × effective_days

cost += kitchen_price (if kitchen selected)
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

## Booking Status Flow

```
pending → confirmed → paid → archived
                   ↘ cancelled → archived
                   ↗ (reopen)
```

Valid statuses: `pending`, `confirmed`, `cancelled`, `archived`, `paid`

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
- **Capability checks:** All admin AJAX handlers check `current_user_can('manage_options')`
- **Payment tokens:** Payment and modification URLs use `modification_token` (per-booking, session-independent) verified with `hash_equals()`
- **Race conditions:** Booking creation uses `START TRANSACTION` / `COMMIT` with conflict re-check inside the transaction
- **Rate limiting:** Hirer registration limited to 5 per IP per hour via transients
- **WooCommerce price:** Always re-read from database in `set_cart_item_price()`, never from cart session
- **Booking lookup:** Requires both reference AND email to prevent enumeration

---

## Email System

14 template types stored in `wp_options` as `mbs_email_template_{type}`. Each has a subject and body with placeholder tags.

Placeholders: `{name}`, `{ref}`, `{space}`, `{date}`, `{time}`, `{amount}`, `{invoice}`, `{admin_email}`, `{phone}`, `{org_name}`, `{org_address}`, `{charity_number}`, `{bank_details}`, `{pay_url}`, `{reason}`, `{organisation}`, `{attendees}`, `{purpose}`

All emails route through `MBS_Email_Queue::send()` which wraps `wp_mail()` with automatic retry on failure.

---

## WP-Cron Jobs

| Hook | Schedule | Class | Purpose |
|---|---|---|---|
| `mbs_daily_reminders` | Daily 7am | MBS_Reminders | Booking reminder emails |
| `mbs_daily_payment_chase` | Daily 9am | MBS_Payment_Chaser | Overdue payment reminders |
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

## Key Conventions

- **PHP class prefix:** `MBS_` (Mathlin Booking System)
- **CSS class prefix:** `nms-` (legacy from original naming, kept for consistency)
- **AJAX action prefix:** `mbs_`
- **Option prefix:** `mbs_`
- **Database table prefix:** `mathlin_`
- **Booking reference format:** `MBS-XXXXXX` (base36)
- **Invoice format:** `INV-MBS-XXXXXX`
- **Series format:** `SER-XXXXXX`

---

## Common Modification Patterns

### Adding a new setting
1. Add default in the relevant `get_*()` method
2. Add field to `admin/views/settings.php`
3. Add to `admin/admin.js` save handler
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
