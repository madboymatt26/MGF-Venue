"""Data coordinator — fetches bookings and manages all timers."""
from __future__ import annotations

import asyncio
import logging
from datetime import date, datetime, time, timedelta
from typing import Any

import aiohttp

from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant, callback
from homeassistant.helpers.event import async_track_point_in_time
from homeassistant.helpers.update_coordinator import DataUpdateCoordinator, UpdateFailed
from homeassistant.util import dt as dt_util

from .const import (
    DOMAIN,
    CONF_WEBSITE_URL,
    CONF_PRE_EVENT_MINUTES,
    CONF_POST_EVENT_MINUTES,
    DEFAULT_PRE_EVENT_MINUTES,
    DEFAULT_POST_EVENT_MINUTES,
    API_PATH_TODAY,
    EVENT_BOOKING_START,
    EVENT_BOOKING_END,
)

_LOGGER = logging.getLogger(__name__)


class MathlinCoordinator(DataUpdateCoordinator):
    """
    Fetches today's bookings from the WordPress plugin REST API.

    Polling strategy:
      - Fetches once on startup
      - Polls again every day at midnight (via a scheduled timer)
      - Does NOT use a fixed scan_interval — we control timing ourselves
    """

    def __init__(self, hass: HomeAssistant, entry: ConfigEntry) -> None:
        super().__init__(
            hass,
            _LOGGER,
            name=DOMAIN,
            # No automatic polling — we drive it manually
            update_interval=None,
        )
        self.entry        = entry
        self.website_url  = entry.data[CONF_WEBSITE_URL]
        self._cancel_midnight: list[Any] = []
        self._cancel_timers:   list[Any] = []

    # ── Data fetch ─────────────────────────────────────────────────────────────

    async def _async_update_data(self) -> list[dict]:
        """Fetch today's confirmed bookings from the REST API."""
        url = self.website_url + API_PATH_TODAY
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(
                    url, timeout=aiohttp.ClientTimeout(total=15)
                ) as resp:
                    if resp.status != 200:
                        raise UpdateFailed(f"API returned HTTP {resp.status}")
                    payload = await resp.json()
                    # The endpoint returns a list directly
                    if not isinstance(payload, list):
                        raise UpdateFailed("Unexpected API response format")
                    _LOGGER.debug(
                        "Fetched %d booking(s) for today from %s", len(payload), url
                    )
                    return payload
        except aiohttp.ClientError as err:
            raise UpdateFailed(f"Network error fetching bookings: {err}") from err

    # ── Midnight poll ──────────────────────────────────────────────────────────

    def schedule_midnight_poll(self) -> None:
        """Schedule a refresh at midnight every day."""
        self._schedule_next_midnight()

    def _schedule_next_midnight(self) -> None:
        now       = dt_util.now()
        tomorrow  = (now + timedelta(days=1)).date()
        midnight  = dt_util.as_local(
            datetime.combine(tomorrow, time(0, 0, 0))
        )
        _LOGGER.debug("Next midnight poll scheduled for %s", midnight)

        @callback
        def _midnight_callback(now_dt: datetime) -> None:
            _LOGGER.info("Midnight poll: refreshing today's bookings")
            self.hass.async_create_task(self._midnight_refresh())

        cancel = async_track_point_in_time(self.hass, _midnight_callback, midnight)
        self._cancel_midnight.append(cancel)

    async def _midnight_refresh(self) -> None:
        """Refresh data and reschedule timers, then queue next midnight poll."""
        await self.async_refresh()
        self.cancel_booking_timers()
        self.schedule_booking_timers()
        self._schedule_next_midnight()

    # ── Booking event timers ───────────────────────────────────────────────────

    def schedule_booking_timers(self) -> None:
        """
        For each booking today, schedule:
          - A 'booking_start' event N minutes before start_time
          - A 'booking_end'   event M minutes after end_time
        """
        if not self.data:
            return

        pre_mins  = self.entry.options.get(CONF_PRE_EVENT_MINUTES,  DEFAULT_PRE_EVENT_MINUTES)
        post_mins = self.entry.options.get(CONF_POST_EVENT_MINUTES, DEFAULT_POST_EVENT_MINUTES)
        now       = dt_util.now()
        today     = now.date()

        for booking in self.data:
            ref       = booking.get("ref", "unknown")
            all_day   = booking.get("all_day", False)
            start_str = booking.get("start_time")
            end_str   = booking.get("end_time")

            if all_day:
                # Outdoor / all-day booking — fire start at 08:00, end at 20:00
                start_dt = dt_util.as_local(datetime.combine(today, time(8, 0)))
                end_dt   = dt_util.as_local(datetime.combine(today, time(20, 0)))
            elif start_str and end_str:
                try:
                    sh, sm = map(int, start_str[:5].split(":"))
                    eh, em = map(int, end_str[:5].split(":"))
                    start_dt = dt_util.as_local(datetime.combine(today, time(sh, sm)))
                    end_dt   = dt_util.as_local(datetime.combine(today, time(eh, em)))
                except (ValueError, AttributeError):
                    _LOGGER.warning("Could not parse times for booking %s", ref)
                    continue
            else:
                continue

            fire_start = start_dt - timedelta(minutes=pre_mins)
            fire_end   = end_dt   + timedelta(minutes=post_mins)

            # Only schedule if the fire time is still in the future
            if fire_start > now:
                self._schedule_event(fire_start, EVENT_BOOKING_START, booking, ref)
                _LOGGER.debug(
                    "Booking %s: start event scheduled for %s", ref, fire_start
                )
            else:
                _LOGGER.debug(
                    "Booking %s: start event time %s already passed, skipping", ref, fire_start
                )

            if fire_end > now:
                self._schedule_event(fire_end, EVENT_BOOKING_END, booking, ref)
                _LOGGER.debug(
                    "Booking %s: end event scheduled for %s", ref, fire_end
                )

    def _schedule_event(
        self,
        fire_at: datetime,
        event_name: str,
        booking: dict,
        ref: str,
    ) -> None:
        """Schedule a single HA event to fire at a specific datetime."""

        @callback
        def _fire(_now: datetime) -> None:
            _LOGGER.info("Firing event %s for booking %s", event_name, ref)
            self.hass.bus.async_fire(event_name, {
                "ref":          booking.get("ref"),
                "space":        booking.get("space"),
                "booking_date": booking.get("booking_date"),
                "start_time":   booking.get("start_time"),
                "end_time":     booking.get("end_time"),
                "attendees":    booking.get("attendees"),
                "purpose":      booking.get("purpose"),
                "kitchen":      booking.get("kitchen"),
                "all_day":      booking.get("all_day"),
            })

        cancel = async_track_point_in_time(self.hass, _fire, fire_at)
        self._cancel_timers.append(cancel)

    # ── Cleanup ────────────────────────────────────────────────────────────────

    def cancel_booking_timers(self) -> None:
        """Cancel all pending booking event timers."""
        for cancel in self._cancel_timers:
            cancel()
        self._cancel_timers.clear()

    def cancel_all_timers(self) -> None:
        """Cancel everything — called on unload."""
        self.cancel_booking_timers()
        for cancel in self._cancel_midnight:
            cancel()
        self._cancel_midnight.clear()
