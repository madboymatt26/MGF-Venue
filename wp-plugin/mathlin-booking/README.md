# Mathlin Booking System – WordPress Plugin

Venue booking system for **Needham Market Scout Group** with Home Assistant integration.

---

## Installation

1. Upload the `mathlin-booking` folder to `/wp-content/plugins/` via SFTP
2. Log in to WordPress admin → **Plugins → Installed Plugins**
3. Activate **Mathlin Booking System**
4. The plugin automatically creates the `wp_mathlin_bookings` table in MariaDB

---

## Usage

### Add the booking system to a page

In the WordPress page editor, add this shortcode:

```
[mathlin_booking]
```

This embeds the full calendar + booking form on any page.

To show the calendar only (no form):
```
[mathlin_calendar]
```

### Admin dashboard

Go to **wp-admin → Scout Bookings** to:
- View all bookings
- Confirm or cancel bookings
- Generate and print invoices
- Search and filter bookings

---

## Home Assistant Integration

### 1. Outbound webhook (WordPress → HA)

When a booking is **confirmed**, WordPress POSTs JSON to your HA webhook.

**Setup:**
1. In HA: Settings → Automations → New Automation → Trigger: Webhook
2. Copy the webhook URL (e.g. `http://homeassistant.local:8123/api/webhook/mathlin_booking`)
3. In WordPress: Scout Bookings → Settings → paste the URL → Save
4. Click **Send Test Webhook** to verify

**Payload sent on confirmation:**
```json
{
  "event":        "booking_confirmed",
  "ref":          "NMS-ABC123",
  "space":        "Main Scout Hall",
  "booking_date": "2026-05-10",
  "start_time":   "18:00:00",
  "end_time":     "21:00:00",
  "attendees":    45,
  "purpose":      "Beaver Colony meeting",
  "kitchen":      false,
  "amount":       75.00
}
```

**Payload sent on cancellation:**
```json
{
  "event":        "booking_cancelled",
  "ref":          "NMS-ABC123",
  "space":        "Main Scout Hall",
  "booking_date": "2026-05-10",
  "start_time":   "18:00:00",
  "end_time":     "21:00:00"
}
```

---

### 2. REST API (HA polls WordPress)

Add to `configuration.yaml`:

```yaml
rest:
  - resource: https://needhamscouts.uk/wp-json/mathlin/v1/bookings/upcoming
    scan_interval: 300
    sensor:
      - name: "Scout Hall Next Booking"
        value_template: >
          {% if value_json | length > 0 %}
            {{ value_json[0].booking_date }} {{ value_json[0].start_time }}
          {% else %}
            No upcoming bookings
          {% endif %}
        json_attributes_path: "$[0]"
        json_attributes:
          - ref
          - space
          - booking_date
          - start_time
          - end_time
          - attendees
          - purpose
          - kitchen

      - name: "Scout Hall Booking Count"
        value_template: "{{ value_json | length }}"

  - resource: https://needhamscouts.uk/wp-json/mathlin/v1/bookings/today
    scan_interval: 3600
    sensor:
      - name: "Scout Hall Today Bookings"
        value_template: "{{ value_json | length }}"
```

---

### 3. Example HA automation

```yaml
automation:
  - alias: "Scout Hall – Pre-heat before booking"
    trigger:
      - platform: webhook
        webhook_id: mathlin_booking
    condition:
      - condition: template
        value_template: "{{ trigger.json.event == 'booking_confirmed' }}"
    action:
      - service: climate.set_temperature
        target:
          entity_id: climate.scout_hall
        data:
          temperature: 19
      - service: notify.mobile_app_your_phone
        data:
          title: "Scout Hall Booking Confirmed"
          message: >
            {{ trigger.json.space }} on {{ trigger.json.booking_date }}
            at {{ trigger.json.start_time }} ({{ trigger.json.attendees }} people)
```

---

## REST API Endpoints

| Endpoint | Auth | Description |
|---|---|---|
| `GET /wp-json/mathlin/v1/bookings/upcoming` | None | Confirmed bookings, next 30 days |
| `GET /wp-json/mathlin/v1/bookings/today` | None | Today's confirmed bookings |
| `GET /wp-json/mathlin/v1/bookings/calendar?year=2026&month=5` | None | Booked dates in a month |
| `GET /wp-json/mathlin/v1/bookings/date/2026-05-10` | None | Bookings on a specific date |
| `GET /wp-json/mathlin/v1/bookings` | WP Admin | All bookings |
| `GET /wp-json/mathlin/v1/bookings/{ref}` | WP Admin | Single booking |
| `POST /wp-json/mathlin/v1/bookings/{ref}/status` | WP Admin | Update status |

---

## Pricing (edit in `includes/class-bookings.php`)

| Space | Rate | Unit |
|---|---|---|
| Main Scout Hall | £25 | per hour |
| Meeting Room | £12 | per hour |
| Outdoor Area | £40 | per day |
| Kitchen add-on | £10 | per session |

---

## File Structure

```
mathlin-booking/
├── mathlin-booking.php       Main plugin file
├── uninstall.php               Cleanup on deletion
├── includes/
│   ├── class-database.php      Table creation
│   ├── class-bookings.php      CRUD + pricing logic
│   ├── class-email.php         Email notifications
│   ├── class-invoice.php       Invoice HTML generation
│   ├── class-rest-api.php      REST API endpoints
│   └── class-homeassistant.php HA webhook + data formatting
├── admin/
│   ├── class-admin.php         Admin menu + AJAX handlers
│   ├── admin.css               Admin styles
│   ├── admin.js                Admin JavaScript
│   └── views/
│       ├── list.php            Bookings list page
│       ├── single.php          Single booking detail
│       ├── invoice.php         Invoice print page
│       └── settings.php        Settings + HA config
└── public/
    ├── class-public.php        Shortcodes + public AJAX
    ├── public.css              Frontend styles (Scout purple)
    ├── public.js               Calendar + form JS
    └── views/
        ├── booking-form.php    Full form shortcode output
        └── calendar.php        Calendar shortcode output
```
