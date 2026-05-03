# Mathlin Booking System

A comprehensive WordPress venue booking plugin built for Needham Market Scout Group, with Home Assistant integration.

**Current Version:** 2.9.2  
**Requires WordPress:** 5.0+  
**Requires PHP:** 7.4+  
**Tested with WordPress:** 6.7  
**License:** GPL-2.0+

---

## Features

### Public Booking System
- Interactive availability calendar with blocked date indicators
- Booking form with real-time cost calculation
- Multi-day and full-day booking support
- Recurring weekly bookings (up to 52 weeks)
- Conflict detection prevents double bookings
- Configurable minimum notice period
- Custom form fields (admin-configurable)
- Terms & Conditions checkbox
- Public/private event visibility toggle
- Scout Use (free) bookings for volunteers
- Honeypot spam prevention on booking form
- Mobile-optimised responsive design

### Customer Accounts (Hirer Portal)
- Self-service registration and login (`[mathlin_portal]`)
- Dashboard showing all bookings, invoices, payment status
- Pre-filled booking form for returning hirers
- Quick account creation after first booking
- Booking status lookup (`[mathlin_status]`)
- Modification and cancellation requests

### Admin Dashboard
- Bookings list with search, filter, and CSV export
- Editable booking details with live cost recalculation
- Custom price override for ad-hoc arrangements
- Calendar view with day-by-day breakdown
- Recurring bookings grouped as collapsible series
- Booking analytics with Chart.js visualisations
- Occupancy/utilisation reports per space
- Audit log tracking all actions with user and timestamp
- Dashboard widget on wp-admin home

### Financial
- Configurable spaces and pricing (hourly + daily rates)
- Kitchen add-on pricing
- WooCommerce payment integration (Stripe, PayPal, etc.)
- Invoice generation with PDF email attachment
- Automatic payment chasing (3 escalating levels)
- Accounting export (Xero, Sage, QuickBooks CSV)
- Bank transfer details on invoices
- Financial year revenue tracking (April–March)

### Email System
- 14 editable email templates with placeholder tags
- Configurable organisation name, address, logo
- Email queue with automatic retry on failure
- Booking reminders (configurable hours before)
- Multi-admin notifications
- Recurring booking summary emails

### Modification & Cancellation Requests
- Bookers can request changes via secure link
- Proper approval queue with pending count badge
- Admin approve/reject with one click
- Auto-applies changes on approval (with conflict check)
- Rejection emails with optional reason

### Integrations
- Home Assistant: webhook on confirmation + REST API polling
- WooCommerce: Pay Now button in emails, auto-status update
- iCal: downloadable .ics files + subscribable calendar feed
- GitHub: auto-update from private repository releases

---

## Installation

1. Upload `mathlin-booking/` to `/wp-content/plugins/`
2. Activate in wp-admin → Plugins
3. Database tables are created automatically

## Shortcodes

| Shortcode | Description |
|---|---|
| `[mathlin_booking]` | Full calendar + booking form |
| `[mathlin_calendar]` | Calendar only (no form) |
| `[mathlin_status]` | Booking status lookup + modification form |
| `[mathlin_portal]` | Hirer login/register + dashboard |

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

---

## Changelog

### v2.9.2 (Latest)
- **Fix:** Fatal syntax error in `class-hirer-portal.php` — extra closing brace prematurely ended the class

### v2.9.1
- **Fix:** Critical error caused by PHP reserved keyword `NAMESPACE` used as class constant
- Renamed to `API_NAMESPACE` in REST API class for PHP 8.x compatibility

### v2.9.0
- **Security:** All date/time calculations now use WordPress timezone (wp_date) instead of server UTC
- **Security:** Honeypot spam prevention on public booking form
- **Mobile:** Comprehensive responsive CSS for phones and tablets
- **Mobile:** iOS zoom prevention on form inputs (font-size: 16px)
- **Mobile:** Full-width buttons and single-column layout on small screens

### v2.8.1
- **New:** Booking Manager role for volunteers
- **Security:** Delete button restricted to Administrators only
- **UX:** Dashboard alerts for failed emails and pending requests

### v2.8.0
- **New:** Bulk actions on admin bookings list (confirm, pay, cancel, archive)
- State transition validation prevents invalid bulk operations

### v2.7.0
- **New:** Custom admin price override
- **Docs:** Complete README rewrite + AI-CONTEXT.md for LLM consumption

### v2.6.1
- **Fix:** Overnight booking (22:00–02:00) spanning 2 calendar dates no longer double-counts days
- Rule: if overnight AND date span = 2, treat as 1 continuous block

### v2.6.0
- **Fix:** Midnight-spanning bookings (22:00–01:00) now calculate hours correctly
- **Fix:** Multi-day hourly bookings now multiply hours × days
- **Fix:** Modification approval checks for conflicts before applying
- **Fix:** Blocked date warning on date picker input
- **Fix:** Calendar loading state during navigation
- **Enhancement:** Recurring email includes total series cost
- **Enhancement:** Scout use toggle noted in audit log

### v2.5.2
- All 14 email types now use editable templates

### v2.5.1
- Configurable logo upload for all emails

### v2.5.0
- Proper approval queue for modification/cancellation requests
- Pending count badge on admin menu

### v2.4.x
- Editable booking details with live cost recalculation
- Series grouping in admin list (collapsible)
- Recurring booking summary email
- Fatal error fix in admin list (PHP syntax)

### v2.3.0
- Admin can edit all booking fields
- Live cost preview with price change warnings
- Notification email on edit with price difference

### v2.2.x
- Scout Use (free) bookings for volunteers
- Recurring booking cost preview
- Payment links work cross-device (token-based)

### v2.1.x
- Hirer portal with login/register
- Public/private event visibility
- Occupancy reports + accounting export
- Quick account creation after booking
- Login prompt on booking form

### v2.0.x
- Email queue with retry
- Custom form fields
- Booking modification requests
- Analytics dashboard with charts

### v1.x
- Core booking system, calendar, invoicing
- Home Assistant integration
- Conflict detection, recurring bookings
- Payment chasing, CSV export, dashboard widget
- WooCommerce payments, auto-archive
- Configurable email templates
