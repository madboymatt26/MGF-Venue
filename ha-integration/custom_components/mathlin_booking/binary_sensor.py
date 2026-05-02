"""Binary sensor — occupancy sensor that's ON during active bookings."""
from __future__ import annotations

import logging
from datetime import datetime, time

from homeassistant.components.binary_sensor import (
    BinarySensorDeviceClass,
    BinarySensorEntity,
)
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.update_coordinator import CoordinatorEntity
from homeassistant.util import dt as dt_util

from .const import DOMAIN
from .coordinator import MathlinCoordinator

_LOGGER = logging.getLogger(__name__)


async def async_setup_entry(
    hass: HomeAssistant,
    entry: ConfigEntry,
    async_add_entities: AddEntitiesCallback,
) -> None:
    """Set up binary sensors from config entry."""
    coordinator: MathlinCoordinator = hass.data[DOMAIN][entry.entry_id]
    async_add_entities([MathlinOccupancySensor(coordinator, entry)], True)


class MathlinOccupancySensor(CoordinatorEntity, BinarySensorEntity):
    """
    Binary sensor: ON when the scout hall is currently in use.

    Checks if the current time falls within any active booking's
    start_time to end_time window. For all-day bookings, considers
    08:00–20:00 as the active window.

    Updates whenever the coordinator refreshes (midnight + startup).
    Also re-evaluates every minute via the HA polling mechanism.
    """

    _attr_device_class = BinarySensorDeviceClass.OCCUPANCY

    def __init__(self, coordinator: MathlinCoordinator, entry: ConfigEntry) -> None:
        super().__init__(coordinator)
        self._entry            = entry
        self._attr_name        = "Scout Hall – Occupied"
        self._attr_unique_id   = f"{entry.entry_id}_occupied"
        self._attr_icon        = "mdi:home-account"

    @property
    def is_on(self) -> bool:
        """Return True if any booking is currently active."""
        if not self.coordinator.data:
            return False

        now   = dt_util.now()
        today = now.date()

        for booking in self.coordinator.data:
            all_day   = booking.get("all_day", False)
            start_str = booking.get("start_time")
            end_str   = booking.get("end_time")

            if all_day:
                start_dt = dt_util.as_local(datetime.combine(today, time(8, 0)))
                end_dt   = dt_util.as_local(datetime.combine(today, time(20, 0)))
            elif start_str and end_str:
                try:
                    sh, sm = map(int, start_str[:5].split(":"))
                    eh, em = map(int, end_str[:5].split(":"))
                    start_dt = dt_util.as_local(datetime.combine(today, time(sh, sm)))
                    end_dt   = dt_util.as_local(datetime.combine(today, time(eh, em)))
                except (ValueError, AttributeError):
                    continue
            else:
                continue

            if start_dt <= now <= end_dt:
                return True

        return False

    @property
    def extra_state_attributes(self) -> dict:
        """Show which booking is currently active, if any."""
        if not self.coordinator.data:
            return {"active_booking": None}

        now   = dt_util.now()
        today = now.date()

        for booking in self.coordinator.data:
            all_day   = booking.get("all_day", False)
            start_str = booking.get("start_time")
            end_str   = booking.get("end_time")

            if all_day:
                start_dt = dt_util.as_local(datetime.combine(today, time(8, 0)))
                end_dt   = dt_util.as_local(datetime.combine(today, time(20, 0)))
            elif start_str and end_str:
                try:
                    sh, sm = map(int, start_str[:5].split(":"))
                    eh, em = map(int, end_str[:5].split(":"))
                    start_dt = dt_util.as_local(datetime.combine(today, time(sh, sm)))
                    end_dt   = dt_util.as_local(datetime.combine(today, time(eh, em)))
                except (ValueError, AttributeError):
                    continue
            else:
                continue

            if start_dt <= now <= end_dt:
                return {
                    "active_booking": booking.get("ref"),
                    "space":          booking.get("space"),
                    "start_time":     booking.get("start_time"),
                    "end_time":       booking.get("end_time"),
                    "attendees":      booking.get("attendees"),
                    "purpose":        booking.get("purpose"),
                    "kitchen":        booking.get("kitchen"),
                }

        return {"active_booking": None}

    @property
    def device_info(self) -> dict:
        return {
            "identifiers":  {(DOMAIN, self._entry.entry_id)},
            "name":         "Needham Market Scout Hall",
            "manufacturer": "Needham Market Scout Group",
            "model":        "Booking System",
            "entry_type":   "service",
        }
