"""Booking interval parsing helpers."""
from __future__ import annotations

from datetime import date, datetime, time, tzinfo
from typing import Any


def parse_booking_interval(
    booking: dict[str, Any], reference_date: date, timezone: tzinfo
) -> tuple[datetime, datetime] | None:
    """Return a timezone-aware interval for a booking on reference_date."""
    if not isinstance(booking, dict):
        return None

    booking_date = booking.get("booking_date")
    if not isinstance(booking_date, str):
        return None

    try:
        if date.fromisoformat(booking_date) != reference_date:
            return None
    except ValueError:
        return None

    if booking.get("all_day") is True:
        return (
            datetime.combine(reference_date, time(8), tzinfo=timezone),
            datetime.combine(reference_date, time(20), tzinfo=timezone),
        )

    start_time = _parse_time(booking.get("start_time"))
    end_time = _parse_time(booking.get("end_time"))
    if start_time is None or end_time is None:
        return None

    start = datetime.combine(reference_date, start_time, tzinfo=timezone)
    end = datetime.combine(reference_date, end_time, tzinfo=timezone)
    if end <= start:
        return None
    return start, end


def _parse_time(value: Any) -> time | None:
    """Parse a WordPress time value without accepting timezone offsets."""
    if not isinstance(value, str) or len(value) not in (5, 8):
        return None
    try:
        parsed = time.fromisoformat(value)
    except ValueError:
        return None
    if parsed.tzinfo is not None:
        return None
    return parsed.replace(second=0, microsecond=0)
