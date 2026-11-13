"""Binary sensor platform — per-space occupancy sensors."""
from __future__ import annotations

import logging
from datetime import datetime

from homeassistant.components.binary_sensor import (
    BinarySensorDeviceClass,
    BinarySensorEntity,
)
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.util import dt as dt_util

from .const import DOMAIN, SPACES, PARENT_SPACE
from .booking_time import parse_booking_interval
from .coordinator import MathlinCoordinator
from .entity import MathlinEntity, heating_entity

_LOGGER = logging.getLogger(__name__)


async def async_setup_entry(
    hass: HomeAssistant,
    entry: ConfigEntry,
    async_add_entities: AddEntitiesCallback,
) -> None:
    """Set up binary sensors from config entry."""
    coordinator: MathlinCoordinator = hass.data[DOMAIN][entry.entry_id]

    entities = []

    # Per-space occupancy sensors
    for space in SPACES:
        entities.append(MathlinSpaceOccupancySensor(coordinator, entry, space))

    # Global occupancy sensor (ON if ANY space is occupied)
    entities.append(MathlinGlobalOccupancySensor(coordinator, entry))

    # Kitchen in use sensor (ON if any active booking has kitchen=true)
    entities.append(MathlinKitchenSensor(coordinator, entry))

    # Separate demand entities; existing occupancy identities remain intact.
    for space in SPACES:
        entities.append(heating_entity(MathlinSpaceOccupancySensor(coordinator, entry, space)))
    entities.append(heating_entity(MathlinGlobalOccupancySensor(coordinator, entry)))
    entities.append(heating_entity(MathlinKitchenSensor(coordinator, entry)))

    async_add_entities(entities, True)


def _bookings_for_space(data: list[dict] | None, space: str) -> list[dict]:
    """Get bookings relevant to a specific space (including parent space bookings)."""
    if not data:
        return []
    return [
        b for b in data
        if b.get("space") == space or b.get("space") == PARENT_SPACE
    ]


def _is_booking_active(booking: dict, now: datetime, today) -> bool:
    """Check if a booking is currently active."""
    interval = parse_booking_interval(
        booking, today, dt_util.DEFAULT_TIME_ZONE
    )
    if interval is None:
        return False
    start_dt, end_dt = interval
    return start_dt <= now < end_dt


def _get_active_booking(bookings: list[dict], now: datetime, today) -> dict | None:
    """Find the first currently active booking from a list."""
    for booking in bookings:
        if _is_booking_active(booking, now, today):
            return booking
    return None


class MathlinSpaceOccupancySensor(MathlinEntity, BinarySensorEntity):
    """
    Per-space occupancy sensor: ON when a specific space is currently in use.

    Considers both direct bookings for this space AND bookings for the
    parent space (Whole Headquarters) which covers all child spaces.
    """

    _attr_device_class = BinarySensorDeviceClass.OCCUPANCY

    def __init__(
        self, coordinator: MathlinCoordinator, entry: ConfigEntry, space: str
    ) -> None:
        super().__init__(coordinator)
        self._entry = entry
        self._space = space
        slug = space.lower().replace(" ", "_")
        self._attr_name      = f"Scout Hall – {space} Occupied"
        self._attr_unique_id = f"{entry.entry_id}_{slug}_occupied"
        self._attr_icon      = "mdi:home-account"

    @property
    def is_on(self) -> bool:
        now   = dt_util.now()
        today = now.date()
        bookings = _bookings_for_space(self.bookings, self._space)
        return _get_active_booking(bookings, now, today) is not None

    @property
    def extra_state_attributes(self) -> dict:
        now   = dt_util.now()
        today = now.date()
        bookings = _bookings_for_space(self.bookings, self._space)
        active = _get_active_booking(bookings, now, today)

        if not active:
            return {
                "active_booking": None,
                "end_time":       None,
                "minutes_until_end": None,
                "kitchen":        None,
            }

        # Calculate minutes until end from the validated booking interval.
        interval = parse_booking_interval(
            active, today, dt_util.DEFAULT_TIME_ZONE
        )
        end_dt = interval[1] if interval is not None else now

        minutes_until_end = max(0, int((end_dt - now).total_seconds() / 60))

        return {
            "active_booking":    active.get("ref"),
            "space":             active.get("space"),
            "start_time":        active.get("start_time"),
            "end_time":          active.get("end_time"),
            "minutes_until_end": minutes_until_end,
            "attendees":         active.get("attendees"),
            "purpose":           active.get("purpose"),
            "kitchen":           active.get("kitchen"),
        }

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


class MathlinGlobalOccupancySensor(MathlinEntity, BinarySensorEntity):
    """
    Global occupancy sensor: ON when ANY space in the building is in use.
    Useful for failsafe automations and dashboard indicators.
    """

    _attr_device_class = BinarySensorDeviceClass.OCCUPANCY

    def __init__(self, coordinator: MathlinCoordinator, entry: ConfigEntry) -> None:
        super().__init__(coordinator)
        self._entry          = entry
        self._attr_name      = "Scout Hall – Occupied"
        self._attr_unique_id = f"{entry.entry_id}_occupied"
        self._attr_icon      = "mdi:home-account"

    @property
    def is_on(self) -> bool:
        if not self.bookings:
            return False
        now   = dt_util.now()
        today = now.date()
        for booking in self.bookings:
            if _is_booking_active(booking, now, today):
                return True
        return False

    @property
    def extra_state_attributes(self) -> dict:
        if not self.bookings:
            return {"active_spaces": []}
        now   = dt_util.now()
        today = now.date()
        active_spaces = []
        for booking in self.bookings:
            if _is_booking_active(booking, now, today):
                active_spaces.append(booking.get("space"))
        return {"active_spaces": active_spaces}

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


class MathlinKitchenSensor(MathlinEntity, BinarySensorEntity):
    """
    Kitchen sensor: ON when any currently active booking has kitchen=true.

    This lets you control the kitchen extractor, heating, or lighting
    independently based on whether the kitchen was actually booked.
    """

    _attr_device_class = BinarySensorDeviceClass.RUNNING

    def __init__(self, coordinator: MathlinCoordinator, entry: ConfigEntry) -> None:
        super().__init__(coordinator)
        self._entry          = entry
        self._attr_name      = "Scout Hall – Kitchen Booked"
        self._attr_unique_id = f"{entry.entry_id}_kitchen_booked"
        self._attr_icon      = "mdi:stove"

    @property
    def is_on(self) -> bool:
        if not self.bookings:
            return False
        now   = dt_util.now()
        today = now.date()
        for booking in self.bookings:
            if _is_booking_active(booking, now, today) and booking.get("kitchen"):
                return True
        return False

    @property
    def extra_state_attributes(self) -> dict:
        if not self.bookings:
            return {"booked_by": None}
        now   = dt_util.now()
        today = now.date()
        for booking in self.bookings:
            if _is_booking_active(booking, now, today) and booking.get("kitchen"):
                return {
                    "booked_by": booking.get("ref"),
                    "space":     booking.get("space"),
                    "end_time":  booking.get("end_time"),
                }
        return {"booked_by": None}

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
