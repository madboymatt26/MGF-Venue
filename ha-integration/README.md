# Mathlin Booking System – Home Assistant Integration

A custom Home Assistant integration that connects to the Mathlin Booking WordPress plugin and automatically fires events before and after each booking so you can run automations for heating, lighting, access control, etc.

---

## Installation

### Option A – Manual (SFTP / file copy)

1. Copy the `custom_components/mathlin_booking` folder into your HA config directory:
   ```
   /config/custom_components/mathlin_booking/
   ```
2. Restart Home Assistant.

### Option B – HACS (if you use it)

1. In HACS → Integrations → ⋮ → Custom repositories
2. Add the repository URL, category: **Integration**
3. Install and restart HA

---

## Setup

1. Go to **Settings → Devices & Services → Add Integration**
2. Search for **Mathlin Booking System**
3. Enter your website URL: `https://needhamscouts.uk`
4. HA will test the connection — if the plugin is active you'll see a success screen

That's it. The integration will immediately fetch today's bookings and schedule timers.

---

## Configuration options

After setup, click **Configure** on the integration card to adjust timing:

| Option | Default | Description |
|---|---|---|
| Minutes before booking start | 60 | How early to fire the `mathlin_booking_start` event |
| Minutes after booking end | 15 | How long after end to fire the `mathlin_booking_end` event |

For example, with defaults: a booking at 18:00–21:00 will fire `booking_start` at **17:00** and `booking_end` at **21:15**.

---

## What it creates

### Sensors

| Entity | State | Description |
|---|---|---|
| `sensor.scout_hall_bookings_today` | Number | Count of bookings today |
| `sensor.scout_hall_main_scout_hall_nms_xxxxx` | Start time | One per booking today |

Each booking sensor has full attributes: `ref`, `space`, `start_time`, `end_time`, `attendees`, `purpose`, `kitchen`, `all_day`.

### Events

| Event | When fired | Payload |
|---|---|---|
| `mathlin_booking_start` | N minutes before booking start | Full booking details |
| `mathlin_booking_end` | N minutes after booking end | Full booking details |

---

## Automation examples

### Pre-heat the hall before a booking

```yaml
automation:
  - alias: "Scout Hall – Pre-heat before booking"
    trigger:
      - platform: event
        event_type: mathlin_booking_start
    action:
      - service: climate.set_temperature
        target:
          entity_id: climate.scout_hall
        data:
          temperature: 19
          hvac_mode: heat
      - service: light.turn_on
        target:
          entity_id: light.scout_hall_main
      - service: notify.mobile_app_your_phone
        data:
          title: "Scout Hall booking starting soon"
          message: >
            {{ trigger.event.data.space }} at {{ trigger.event.data.start_time }}
            — {{ trigger.event.data.attendees }} people
            ({{ trigger.event.data.purpose }})
```

### Turn everything off after a booking

```yaml
automation:
  - alias: "Scout Hall – Shutdown after booking"
    trigger:
      - platform: event
        event_type: mathlin_booking_end
    action:
      - service: climate.set_temperature
        target:
          entity_id: climate.scout_hall
        data:
          temperature: 14
          hvac_mode: heat
      - service: light.turn_off
        target:
          entity_id:
            - light.scout_hall_main
            - light.scout_hall_kitchen
      - service: notify.mobile_app_your_phone
        data:
          title: "Scout Hall booking ended"
          message: >
            {{ trigger.event.data.space }} booking finished
            (ref: {{ trigger.event.data.ref }})
```

### Kitchen-specific automation

```yaml
automation:
  - alias: "Scout Hall – Turn on kitchen extractor if kitchen booked"
    trigger:
      - platform: event
        event_type: mathlin_booking_start
    condition:
      - condition: template
        value_template: "{{ trigger.event.data.kitchen == true }}"
    action:
      - service: switch.turn_on
        target:
          entity_id: switch.kitchen_extractor
```

### Different heating for different spaces

```yaml
automation:
  - alias: "Scout Hall – Space-specific heating"
    trigger:
      - platform: event
        event_type: mathlin_booking_start
    action:
      - choose:
          - conditions:
              - condition: template
                value_template: "{{ trigger.event.data.space == 'Main Scout Hall' }}"
            sequence:
              - service: climate.set_temperature
                target: { entity_id: climate.main_hall }
                data: { temperature: 19 }
          - conditions:
              - condition: template
                value_template: "{{ trigger.event.data.space == 'Meeting Room' }}"
            sequence:
              - service: climate.set_temperature
                target: { entity_id: climate.meeting_room }
                data: { temperature: 20 }
```

---

## How it works

```
Midnight
  │
  ├─ Polls https://needhamscouts.uk/wp-json/mathlin/v1/bookings/today
  │
  ├─ Updates sensors
  │
  └─ Schedules timers for each booking:
       ├─ [start_time - pre_minutes]  → fires mathlin_booking_start
       └─ [end_time   + post_minutes] → fires mathlin_booking_end
```

Timers are rescheduled every midnight with fresh data. If HA restarts during the day, the integration fetches current data immediately on startup and reschedules any timers that haven't fired yet.

---

## Troubleshooting

**"Plugin not found" error during setup**
- Make sure the Mathlin Booking WordPress plugin is installed and activated
- Check that the WordPress REST API is accessible (not blocked by a security plugin)
- Try visiting `https://needhamscouts.uk/wp-json/mathlin/v1/bookings/today` in a browser — it should return `[]` or a JSON array

**Events not firing**
- Check HA logs: Settings → System → Logs, filter by `mathlin_booking`
- Verify the booking is confirmed (not pending) in the WordPress admin
- Check the timing — if the pre-event time has already passed when HA starts, that timer is skipped for today

**Sensors not updating**
- The integration only polls at midnight. To force a refresh: Settings → Devices & Services → Mathlin Booking → ⋮ → Reload
