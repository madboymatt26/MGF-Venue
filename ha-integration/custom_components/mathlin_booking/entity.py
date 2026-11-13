"""Base entity for the Mathlin Booking integration."""
from __future__ import annotations

from homeassistant.helpers.update_coordinator import CoordinatorEntity

from .coordinator import MathlinCoordinator
from .heating_policy import heating_requested


class MathlinEntity(CoordinatorEntity[MathlinCoordinator]):
    """Base entity whose availability follows the latest refresh result."""

    _heating_only = False

    @property
    def bookings(self):
        """General occupancy is never filtered by a heating preference."""
        data = self.coordinator.data
        if not self._heating_only or data is None:
            return data
        return [booking for booking in data if heating_requested(booking)]


def heating_entity(entity):
    """Give a newly constructed (not yet registered) entity a separate identity."""
    entity._heating_only = True
    entity._attr_name = entity._attr_name.replace("Scout Hall – ", "Scout Hall – Heating ", 1)
    entity._attr_unique_id += "_heating"
    return entity
