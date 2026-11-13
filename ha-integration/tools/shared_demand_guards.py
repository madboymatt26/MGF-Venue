"""Prepare narrowly scoped HA thermostat guards; never connect or save to HA.

Input is an operator-reviewed automation export. Output remains a Python object,
so callers can inspect/preserve it and apply only the two matching controllers.
Safety/defrost/ASHP controls and existing start actions are deliberately untouched.
"""
from copy import deepcopy

SCOUT_ID = "1779701634022"
BOOKING_ID = "1779712927079"
ROOMS = {
    "switch.st_802_8cbfeaa6e444_enable_thermostat": "main",
    "switch.meeting_room_thermostat_enable_thermostat": "meeting",
    "switch.kitchen_thermostat_enable_thermostat": "kitchen",
    "switch.hallway_thermostat_enable_thermostat": "hallway",
}
PREFIX = "needham_market_scout_hall_scout_hall_"
GROUPS = ("scouts", "beavers", "explorers", "squirrels", "cubs")


def booking_demand(room):
    """Current zone demand, including the existing dynamic AC preheat window.

    Unknown occupancy vetoes a shutdown; it never starts heating. Unknown next
    booking alone does not hold heat indefinitely when occupancy is known off.
    """
    return """
{% set rate = states('input_number.preheat_rate_per_degree') | float(10) %}
{% set cap = states('input_number.preheat_max_minutes') | float(60) %}
{% set temp = states('sensor.scout_hut_average_air_temperature') | float(18) %}
{% set target = states('input_number.booking_setpoint') | float(21) %}
{% set lead = [((target - temp) | abs) * rate, cap] | min | round(0) %}
{% set ns = namespace(main=false, meeting=false, kitchen=false) %}
{% for name, key in [('main_scout_hall','main'),('meeting_room','meeting')] %}
  {% set occupied = states('binary_sensor.PREFIX' ~ name ~ '_occupied') %}
  {% set sensor = 'sensor.PREFIX' ~ name ~ '_next_booking' %}
  {% set mins = state_attr(sensor, 'minutes_until_start') %}
  {% set preheat = states(sensor) not in ['None','none','unknown','unavailable','']
      and is_number(mins) and 0 <= mins | float <= lead | float %}
  {% if key == 'main' %}{% set ns.main = occupied != 'off' or preheat %}
  {% else %}{% set ns.meeting = occupied != 'off' or preheat %}{% endif %}
  {% if preheat and state_attr(sensor,'kitchen') == true %}
    {% set ns.kitchen = true %}
  {% endif %}
{% endfor %}
{% set ns.kitchen = ns.kitchen or states('binary_sensor.PREFIXkitchen_booked') != 'off' %}
{{ RESULT }}
""".replace("PREFIX", PREFIX).replace("RESULT", {
        "main": "ns.main", "meeting": "ns.meeting", "kitchen": "ns.kitchen",
        "hallway": "ns.main or ns.meeting or ns.kitchen",
    }[room]).strip()


def scout_demand():
    """Use the unchanged schedule's holiday gate and attendance policy.

    This only protects demand from another controller's stop. It does not change
    the Scout controller's own attendance cut-off, starts, stops or setpoints.
    """
    # Evaluate the original HA templates as preceding variables, not interpolated
    # template source. Invalid configured times conservatively veto shutdown.
    return """
{% set ns = namespace(demand=false) %}
{% if mbs_scout_enabled | bool %}
  {% set current = as_timestamp(now()) %}
  {% set rate = states('input_number.preheat_rate_per_degree') | float(10) %}
  {% set cap = states('input_number.preheat_max_minutes') | float(60) %}
  {% set temp = states('sensor.scout_hut_average_air_temperature') | float(18) %}
  {% for group in ['scouts','beavers','explorers','squirrels','cubs'] %}
    {% set day = states('input_select.' ~ group ~ '_day') %}
    {% set start = states('input_datetime.' ~ group ~ '_start_time') %}
    {% set stop = states('input_datetime.' ~ group ~ '_stop_time') %}
    {% if day == now().strftime('%A') %}
      {% if start in ['unknown','unavailable','none',''] or stop in ['unknown','unavailable','none',''] %}
        {% set ns.demand = true %}
      {% else %}
        {% set start_ts = as_timestamp(today_at(start)) %}
        {% set stop_ts = as_timestamp(today_at(stop)) %}
        {% set target = states('input_number.' ~ group ~ '_setpoint') | float(21) %}
        {% set lead = [((target - temp) | abs) * rate, cap] | min | round(0) %}
        {% if start_ts - lead * 60 <= current < stop_ts
            and (current < start_ts + 300 or mbs_scout_presence | bool) %}
          {% set ns.demand = true %}
        {% endif %}
      {% endif %}
    {% endif %}
  {% endfor %}
{% endif %}
{{ ns.demand }}
""".strip()


def _guard_actions(value, include_scout, count):
    if isinstance(value, list):
        return [_guard_actions(item, include_scout, count) for item in value]
    if not isinstance(value, dict):
        return value
    if value.get("action", value.get("service")) == "switch.turn_off":
        if set(value) - {"action", "service", "target", "alias"}:
            raise ValueError("Unexpected thermostat stop options; review manually")
        ids = value.get("target", {}).get("entity_id", [])
        ids = [ids] if isinstance(ids, str) else ids
        if not ids or any(entity not in ROOMS for entity in ids):
            raise ValueError("Unexpected stop target; refusing broad transformation")
        actions = []
        for entity in ids:
            stop = deepcopy(value)
            stop["target"]["entity_id"] = [entity]
            var = "mbs_booking_" + ROOMS[entity]
            protection = var + (" | bool or mbs_scout_demand | bool" if include_scout else " | bool")
            actions.append({"if": [{"condition": "template", "value_template":
                "{{ not (" + protection + ") }}"}], "then": [stop]})
            count[0] += 1
        return {"alias": "Shared-demand guard (room-specific)", "sequence": actions}
    return {key: _guard_actions(item, include_scout, count) for key, item in value.items()}


def prepare(controllers):
    by_id = {item["id"]: item for item in controllers}
    scout, booking = by_id[SCOUT_ID], by_id[BOOKING_ID]
    if scout["alias"] != "Scheduled Thermostat Control (Preheat + UFH)" or booking["alias"] != "Booking HVAC Control (Space-Aware Preheat + Auto Off)":
        raise ValueError("Controller identity mismatch")
    if any(key.startswith("mbs_") for item in (scout, booking) for key in item.get("variables", {})):
        raise ValueError("Already patched or unexpected MBS variables")
    result = deepcopy(controllers)
    counts = {}
    for item in result:
        if item["id"] not in (SCOUT_ID, BOOKING_ID):
            continue
        include_scout = item["id"] == BOOKING_ID
        count = [0]
        item["actions"] = _guard_actions(item["actions"], include_scout, count)
        counts[item["id"]] = count[0]
        variables = item.setdefault("variables", {})
        for room in ("main", "meeting", "kitchen", "hallway"):
            variables["mbs_booking_" + room] = booking_demand(room)
        if include_scout:
            variables["mbs_scout_enabled"] = scout["conditions"][0]["value_template"]
            variables["mbs_scout_presence"] = scout["variables"]["anyone_home"]
            variables["mbs_scout_demand"] = scout_demand()
            # Reconcile a removed/replaced next request only when the previous
            # request was already within preheat. Never shut down merely because
            # an idle room has no next booking (manual heating must remain alone).
            for suffix, room in (("main_scout_hall", "main"), ("meeting_room", "meeting")):
                trigger_id = "mbs_" + room + "_request_changed"
                item["triggers"].append({"trigger": "state", "entity_id":
                    "sensor." + PREFIX + suffix + "_next_booking", "id": trigger_id})
                condition = """{{ trigger.id | default('') == 'TRIGGER'
and trigger.from_state is not none
and trigger.from_state.state not in ['None','none','unknown','unavailable','']
and is_number(trigger.from_state.attributes.get('minutes_until_start'))
and 0 <= trigger.from_state.attributes.get('minutes_until_start') | float <= ac_lead_time | float
and not (mbs_booking_ROOM | bool) }}""".replace("TRIGGER", trigger_id).replace("ROOM", room)
                stop = {"action": "switch.turn_off", "target": {"entity_id":
                    [entity for entity, zone in ROOMS.items() if zone in (room, "kitchen", "hallway")]}}
                item["actions"].append({"alias": "Reconcile removed preheat request: " + room,
                    "if": [{"condition": "template", "value_template": condition}],
                    "then": [_guard_actions(stop, True, [0])]})
    if counts != {SCOUT_ID: 36, BOOKING_ID: 6}:
        raise ValueError(f"Stop inventory changed; manual review required: {counts}")
    return result
