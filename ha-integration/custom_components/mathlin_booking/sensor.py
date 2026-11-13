"""Sensor platform — per-space booking sensors plus a global summary."""
from __future__ import annotations

import logging
from datetime import datetime

from homeassistant.components.sensor import SensorEntity
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.util import dt as dt_util

from .const import (
    DOMAIN,
    SPACES,
    PARENT_SPACE,
    ATTR_REF, ATTR_SPACE, ATTR_START_TIME, ATTR_END_TIME,
    ATTR_ATTENDEES, ATTR_PURPOSE, ATTR_KITCHEN, ATTR_ALL_DAY, ATTR_DATE,
)
from .booking_time import parse_booking_interval
from .coordinator import MathlinCoordinator
from .entity import MathlinEntity, heating_entity

_LOGGER = logging.getLogger(__name__)


async def async_setup_entry(
    hass: HomeAssistant,
    entry: ConfigEntry,
    async_add_entities: AddEntitiesCallback,
) -> None:
    """Set up sensors from config entry."""
    coordinator: MathlinCoordinator = hass.data[DOMAIN][entry.entry_id]

    entities: list[SensorEntity] = [
        # Global summary sensor
        MathlinSummarySensor(coordinator, entry),
    ]

    # Per-space "next booking" and "current booking" sensors
    for space in SPACES:
        entities.append(MathlinSpaceNextBookingSensor(coordinator, entry, space))
        entities.append(MathlinSpaceCurrentBookingSensor(coordinator, entry, space))

    entities.append(heating_entity(MathlinSummarySensor(coordinator, entry)))
    for space in SPACES:
        entities.append(heating_entity(MathlinSpaceNextBookingSensor(coordinator, entry, space)))
        entities.append(heating_entity(MathlinSpaceCurrentBookingSensor(coordinator, entry, space)))

    async_add_entities(entities, True)


class MathlinSummarySensor(MathlinEntity, SensorEntity):
    """
    Summary sensor: state = number of hirer bookings today.
    Always present, updates at midnight.
    """

    def __init__(self, coordinator: MathlinCoordinator, entry: ConfigEntry) -> None:
        super().__init__(coordinator)
        self._entry          = entry
        self._attr_name      = "Scout Hall – Bookings Today"
        self._attr_unique_id = f"{entry.entry_id}_summary"
        self._attr_icon      = "mdi:calendar-check"

    @property
    def native_value(self) -> int:
        return len(self.bookings) if self.bookings else 0

    @property
    def extra_state_attributes(self) -> dict:
        if not self.bookings:
            return {"bookings": []}
        return {
            "bookings": [
                {
                    "ref":        b.get("ref"),
                    "space":      b.get("space"),
                    "start_time": b.get("start_time"),
                    "end_time":   b.get("end_time"),
                    "attendees":  b.get("attendees"),
                    "purpose":    b.get("purpose"),
                    "kitchen":    b.get("kitchen"),
                    "all_day":    b.get("all_day"),
                }
                for b in self.bookings
            ]
        }

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


# ── Per-Space Sensors ──────────────────────────────────────────────────────────


def _bookings_for_space(data: list[dict] | None, space: str) -> list[dict]:
    """
    Get bookings relevant to a specific space.

    A booking is relevant if:
      - It's booked directly for this space, OR
      - It's booked for the parent space (Whole Headquarters) which covers all child spaces
    """
    if not data:
        return []
    return [
        b for b in data
        if b.get("space") == space or b.get("space") == PARENT_SPACE
    ]


def _parse_booking_time(booking: dict, today) -> tuple[datetime, datetime] | None:
    """Parse a booking into a timezone-aware interval for today."""
    return parse_booking_interval(booking, today, dt_util.DEFAULT_TIME_ZONE)


class MathlinSpaceNextBookingSensor(MathlinEntity, SensorEntity):
    """
    Per-space sensor: shows the next upcoming booking for a specific space.

    State = start time of the next booking (or "None").
    Attributes include minutes_until_start, end_time, kitchen, etc.
    Updates every 60 seconds for a live countdown.
    """

    def __init__(
        self, coordinator: MathlinCoordinator, entry: ConfigEntry, space: str
    ) -> None:
        super().__init__(coordinator)
        self._entry = entry
        self._space = space
        slug = space.lower().replace(" ", "_")
        self._attr_name      = f"Scout Hall – {space} Next Booking"
        self._attr_unique_id = f"{entry.entry_id}_{slug}_next_booking"
        self._attr_icon      = "mdi:calendar-arrow-right"

    def _get_next_booking(self) -> tuple[dict, datetime, datetime] | None:
        """Find the next booking for this space that hasn't started yet."""
        now   = dt_util.now()
        today = now.date()
        bookings = _bookings_for_space(self.bookings, self._space)
        upcoming = []

        for booking in bookings:
            times = _parse_booking_time(booking, today)
            if not times:
                continue
            start_dt, end_dt = times
            if start_dt > now:
                upcoming.append((start_dt, end_dt, booking))

        if not upcoming:
            return None

        upcoming.sort(key=lambda x: x[0])
        start_dt, end_dt, booking = upcoming[0]
        return booking, start_dt, end_dt

    @property
    def native_value(self) -> str:
        result = self._get_next_booking()
        if not result:
            return "None"
        booking, start_dt, _ = result
        if booking.get("all_day"):
            return "08:00"
        start = booking.get("start_time", "")
        return start[:5] if start else "None"

    @property
    def extra_state_attributes(self) -> dict:
        result = self._get_next_booking()
        if not result:
            return {
                "minutes_until_start": None,
                "minutes_until_end":   None,
                ATTR_REF:        None,
                ATTR_SPACE:      None,
                ATTR_START_TIME: None,
                ATTR_END_TIME:   None,
                ATTR_ATTENDEES:  None,
                ATTR_PURPOSE:    None,
                ATTR_KITCHEN:    None,
                ATTR_ALL_DAY:    None,
            }

        booking, start_dt, end_dt = result
        now = dt_util.now()
        minutes_until_start = max(0, int((start_dt - now).total_seconds() / 60))
        minutes_until_end   = max(0, int((end_dt - now).total_seconds() / 60))

        return {
            "minutes_until_start": minutes_until_start,
            "minutes_until_end":   minutes_until_end,
            ATTR_REF:        booking.get("ref"),
            ATTR_SPACE:      booking.get("space"),
            ATTR_START_TIME: booking.get("start_time"),
            ATTR_END_TIME:   booking.get("end_time"),
            ATTR_ATTENDEES:  booking.get("attendees"),
            ATTR_PURPOSE:    booking.get("purpose"),
            ATTR_KITCHEN:    booking.get("kitchen"),
            ATTR_ALL_DAY:    booking.get("all_day"),
        }

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


class MathlinSpaceCurrentBookingSensor(MathlinEntity, SensorEntity):
    """
    Per-space sensor: shows the currently active booking for a specific space.

    State = end time of the current booking (or "None" if space is free).
    Attributes include minutes_until_end, kitchen, ref, etc.
    Updates every 60 seconds.
    """

    def __init__(
        self, coordinator: MathlinCoordinator, entry: ConfigEntry, space: str
    ) -> None:
        super().__init__(coordinator)
        self._entry = entry
        self._space = space
        slug = space.lower().replace(" ", "_")
        self._attr_name      = f"Scout Hall – {space} Current Booking"
        self._attr_unique_id = f"{entry.entry_id}_{slug}_current_booking"
        self._attr_icon      = "mdi:calendar-clock"

    def _get_current_booking(self) -> tuple[dict, datetime, datetime] | None:
        """Find the currently active booking for this space."""
        now   = dt_util.now()
        today = now.date()
        bookings = _bookings_for_space(self.bookings, self._space)

        for booking in bookings:
            times = _parse_booking_time(booking, today)
            if not times:
                continue
            start_dt, end_dt = times
            if start_dt <= now < end_dt:
                return booking, start_dt, end_dt

        return None

    @property
    def native_value(self) -> str:
        result = self._get_current_booking()
        if not result:
            return "None"
        booking, _, end_dt = result
        end = booking.get("end_time", "")
        return end[:5] if end else "20:00"

    @property
    def extra_state_attributes(self) -> dict:
        result = self._get_current_booking()
        if not result:
            return {
                "minutes_until_end": None,
                ATTR_REF:        None,
                ATTR_SPACE:      None,
                ATTR_START_TIME: None,
                ATTR_END_TIME:   None,
                ATTR_ATTENDEES:  None,
                ATTR_PURPOSE:    None,
                ATTR_KITCHEN:    None,
                ATTR_ALL_DAY:    None,
            }

        booking, start_dt, end_dt = result
        now = dt_util.now()
        minutes_until_end = max(0, int((end_dt - now).total_seconds() / 60))

        return {
            "minutes_until_end": minutes_until_end,
            ATTR_REF:        booking.get("ref"),
            ATTR_SPACE:      booking.get("space"),
            ATTR_START_TIME: booking.get("start_time"),
            ATTR_END_TIME:   booking.get("end_time"),
            ATTR_ATTENDEES:  booking.get("attendees"),
            ATTR_PURPOSE:    booking.get("purpose"),
            ATTR_KITCHEN:    booking.get("kitchen"),
            ATTR_ALL_DAY:    booking.get("all_day"),
        }

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


# ── Helpers ────────────────────────────────────────────────────────────────────

def _device_info(entry: ConfigEntry) -> dict:
    return {
        "identifiers":  {(DOMAIN, entry.entry_id)},
        "name":         "Needham Market Scout Hall",
        "manufacturer": "Needham Market Scout Group",
        "model":        "Booking System",
        "entry_type":   "service",
    }
