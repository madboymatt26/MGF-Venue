"""Sensor platform — one sensor per booking today, plus a summary sensor."""
from __future__ import annotations

import logging
from datetime import datetime

from homeassistant.components.sensor import SensorEntity
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant, callback
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.update_coordinator import CoordinatorEntity

from .const import (
    DOMAIN,
    ATTR_REF, ATTR_SPACE, ATTR_START_TIME, ATTR_END_TIME,
    ATTR_ATTENDEES, ATTR_PURPOSE, ATTR_KITCHEN, ATTR_ALL_DAY, ATTR_DATE,
)
from .coordinator import MathlinCoordinator

_LOGGER = logging.getLogger(__name__)


async def async_setup_entry(
    hass: HomeAssistant,
    entry: ConfigEntry,
    async_add_entities: AddEntitiesCallback,
) -> None:
    """Set up sensors from config entry."""
    coordinator: MathlinCoordinator = hass.data[DOMAIN][entry.entry_id]

    # Summary sensor always exists
    entities: list[SensorEntity] = [MathlinSummarySensor(coordinator, entry)]

    # Individual booking sensors for today
    if coordinator.data:
        for booking in coordinator.data:
            entities.append(MathlinBookingSensor(coordinator, entry, booking))

    async_add_entities(entities, True)

    # When coordinator refreshes (midnight), rebuild booking sensors
    @callback
    def _handle_coordinator_update() -> None:
        """Add new sensors when today's bookings refresh."""
        if not coordinator.data:
            return
        existing_refs = {
            e.unique_id
            for e in hass.data[DOMAIN].get("entities_" + entry.entry_id, [])
        }
        new_entities = []
        for booking in coordinator.data:
            uid = f"{entry.entry_id}_{booking.get('ref', 'unknown')}"
            if uid not in existing_refs:
                new_entities.append(MathlinBookingSensor(coordinator, entry, booking))
        if new_entities:
            async_add_entities(new_entities, True)

    coordinator.async_add_listener(_handle_coordinator_update)


class MathlinSummarySensor(CoordinatorEntity, SensorEntity):
    """
    Summary sensor: state = number of bookings today.
    Always present, updates at midnight.
    """

    def __init__(self, coordinator: MathlinCoordinator, entry: ConfigEntry) -> None:
        super().__init__(coordinator)
        self._entry        = entry
        self._attr_name    = "Scout Hall – Bookings Today"
        self._attr_unique_id = f"{entry.entry_id}_summary"
        self._attr_icon    = "mdi:calendar-check"

    @property
    def native_value(self) -> int:
        """Number of bookings today."""
        return len(self.coordinator.data) if self.coordinator.data else 0

    @property
    def extra_state_attributes(self) -> dict:
        """List all today's bookings as attributes."""
        if not self.coordinator.data:
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
                for b in self.coordinator.data
            ]
        }

    @property
    def device_info(self) -> dict:
        return _device_info(self._entry)


class MathlinBookingSensor(CoordinatorEntity, SensorEntity):
    """
    One sensor per booking today.
    State = start time (or 'All day').
    Attributes carry all booking details for use in automations.
    """

    def __init__(
        self,
        coordinator: MathlinCoordinator,
        entry: ConfigEntry,
        booking: dict,
    ) -> None:
        super().__init__(coordinator)
        self._entry   = entry
        self._booking = booking
        ref           = booking.get("ref", "unknown")
        space         = booking.get("space", "Booking")

        self._attr_name      = f"Scout Hall – {space} ({ref})"
        self._attr_unique_id = f"{entry.entry_id}_{ref}"
        self._attr_icon      = _space_icon(space)

    @property
    def native_value(self) -> str:
        """State is the start time, or 'All day' for outdoor bookings."""
        if self._booking.get("all_day"):
            return "All day"
        start = self._booking.get("start_time", "")
        # Trim seconds if present (HH:MM:SS → HH:MM)
        return start[:5] if start else "Unknown"

    @property
    def extra_state_attributes(self) -> dict:
        b = self._booking
        return {
            ATTR_REF:        b.get("ref"),
            ATTR_SPACE:      b.get("space"),
            ATTR_DATE:       b.get("booking_date"),
            ATTR_START_TIME: b.get("start_time"),
            ATTR_END_TIME:   b.get("end_time"),
            ATTR_ATTENDEES:  b.get("attendees"),
            ATTR_PURPOSE:    b.get("purpose"),
            ATTR_KITCHEN:    b.get("kitchen"),
            ATTR_ALL_DAY:    b.get("all_day"),
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


def _space_icon(space: str) -> str:
    icons = {
        "Main Scout Hall": "mdi:home-group",
        "Meeting Room":    "mdi:account-group",
        "Outdoor Area":    "mdi:tree",
    }
    return icons.get(space, "mdi:calendar")
