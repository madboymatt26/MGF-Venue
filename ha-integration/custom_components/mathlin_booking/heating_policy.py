"""Booking heating eligibility; deliberately independent of physical controls.

Never use removal of one request as a global thermostat-off instruction.
Legacy unlabelled external feeds remain compatible. Explicit flags fail closed.
"""
from collections.abc import Mapping


def normalise_heating_booking(booking: object) -> object:
    """Keep the installed 2.x parent-space contract without altering WP data.

    HQ's existing room sensors and automation templates use Whole Headquarters.
    Preserve the source label separately; do not rename stored booking resources.
    """
    if isinstance(booking, Mapping) and booking.get("space") == "Whole Centre":
        return {**booking, "space": "Whole Headquarters", "venue_space": "Whole Centre"}
    return booking


def heating_requested(booking: object) -> bool:
    """Reject malformed data, explicit opt-outs, and unlabelled Scout bookings."""
    if not isinstance(booking, Mapping):
        return False
    if "heating_required" in booking:
        return booking["heating_required"] is True
    return booking.get("scout_use", False) in (False, 0, "0", None)
