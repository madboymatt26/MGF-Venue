"""Data coordinator — fetches bookings and manages all timers."""
from __future__ import annotations

import asyncio
from copy import deepcopy
import logging
from datetime import datetime, timedelta
from typing import Any

import aiohttp

from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant, callback
from homeassistant.exceptions import ConfigEntryAuthFailed
from homeassistant.helpers.aiohttp_client import async_get_clientsession
from homeassistant.helpers.event import async_track_point_in_time
from homeassistant.helpers.update_coordinator import DataUpdateCoordinator, UpdateFailed
from homeassistant.util import dt as dt_util

from .booking_time import parse_booking_interval
from .heating_policy import heating_requested, normalise_heating_booking
from .const import (
    API_PATH_OCCUPANCY_TODAY,
    CONF_APPLICATION_PASSWORD,
    CONF_GAP_MINUTES,
    CONF_POST_EVENT_MINUTES,
    CONF_PRE_EVENT_MINUTES,
    CONF_USERNAME,
    CONF_WEBSITE_URL,
    DEFAULT_GAP_MINUTES,
    DEFAULT_POST_EVENT_MINUTES,
    DEFAULT_PRE_EVENT_MINUTES,
    DOMAIN,
    EVENT_BOOKING_END,
    EVENT_BOOKING_START,
)

_LOGGER = logging.getLogger(__name__)


class MathlinCoordinator(DataUpdateCoordinator[list[dict[str, Any]]]):
    """Fetch today's bookings and schedule booking boundary events."""

    def __init__(self, hass: HomeAssistant, entry: ConfigEntry) -> None:
        super().__init__(
            hass,
            _LOGGER,
            name=DOMAIN,
            update_interval=timedelta(minutes=5),
        )
        self.entry = entry
        self.website_url = entry.data[CONF_WEBSITE_URL]
        self._cancel_midnight: list[Any] = []
        self._cancel_timers: list[Any] = []
        self._timer_data = None

    async def _async_update_data(self) -> list[dict[str, Any]]:
        """Fetch and validate today's confirmed bookings."""
        username = self.entry.data.get(CONF_USERNAME)
        application_password = self.entry.data.get(CONF_APPLICATION_PASSWORD)
        if not username or not application_password:
            raise ConfigEntryAuthFailed("WordPress credentials are required")

        url = self.website_url + API_PATH_OCCUPANCY_TODAY
        session = async_get_clientsession(self.hass)
        try:
            async with session.get(
                url,
                auth=aiohttp.BasicAuth(username, application_password),
                timeout=aiohttp.ClientTimeout(total=15),
            ) as response:
                if response.status in (401, 403):
                    raise ConfigEntryAuthFailed("WordPress authentication failed")
                if response.status != 200:
                    raise UpdateFailed(f"Booking API returned HTTP {response.status}")
                payload = await response.json()
        except asyncio.TimeoutError as err:
            raise UpdateFailed("Booking API request timed out") from err
        except ConfigEntryAuthFailed:
            raise
        except aiohttp.ClientError as err:
            raise UpdateFailed(f"Booking API request failed: {type(err).__name__}") from err

        if not isinstance(payload, list):
            raise UpdateFailed("Booking API returned an unexpected payload")

        today = dt_util.now().date()
        valid_bookings: list[dict[str, Any]] = []
        invalid_count = 0
        for booking in payload:
            booking = normalise_heating_booking(booking)
            if (not isinstance(booking, dict)
                or not isinstance(booking.get("heating_required"), bool)
                or not isinstance(booking.get("scout_use"), bool)
                or booking.get("integration_scope") != "booking_occupancy"):
                raise UpdateFailed("Booking API lacks the separate occupancy/heating contract")
            if (
                not isinstance(booking, dict)
                or not isinstance(booking.get("space"), str)
                or not booking["space"].strip()
                or not isinstance(booking.get("all_day", False), bool)
                or parse_booking_interval(
                    booking, today, dt_util.DEFAULT_TIME_ZONE
                )
                is None
            ):
                invalid_count += 1
                continue
            valid_bookings.append(booking)

        if invalid_count:
            _LOGGER.warning(
                "Ignored %d malformed or wrong-date booking record(s)", invalid_count
            )
        _LOGGER.debug("Fetched %d valid booking(s) for today", len(valid_bookings))
        return valid_bookings

    @callback
    def async_update_listeners(self) -> None:
        """Reconcile timers for polled AND pushed updates, never failed polls.

        HA's regular polling assigns data directly; it does not call the push-only
        async_set_updated_data hook. Entity-boundary notifications must not keep
        rebuilding the same timers, hence the independent content snapshot.
        """
        if self.last_update_success and self.data != self._timer_data:
            self._timer_data = deepcopy(self.data)
            self.schedule_booking_timers()
        super().async_update_listeners()

    def schedule_midnight_poll(self) -> None:
        """Schedule an extra refresh at each local midnight."""
        self._schedule_next_midnight()

    def _schedule_next_midnight(self) -> None:
        now = dt_util.now()
        tomorrow = (now + timedelta(days=1)).date()
        midnight = datetime.combine(
            tomorrow, datetime.min.time(), tzinfo=dt_util.DEFAULT_TIME_ZONE
        )

        @callback
        def _midnight_callback(_now: datetime) -> None:
            self.hass.async_create_task(self._midnight_refresh())

        cancel = async_track_point_in_time(self.hass, _midnight_callback, midnight)
        self._cancel_midnight.append(cancel)

    async def _midnight_refresh(self) -> None:
        """Refresh at day rollover and schedule the following midnight."""
        await self.async_refresh()
        self._schedule_next_midnight()

    def schedule_booking_timers(self) -> None:
        """Schedule automation events and exact entity updates for fresh data."""
        self.cancel_booking_timers()
        if not self.data:
            return

        pre_mins = self.entry.options.get(
            CONF_PRE_EVENT_MINUTES, DEFAULT_PRE_EVENT_MINUTES
        )
        post_mins = self.entry.options.get(
            CONF_POST_EVENT_MINUTES, DEFAULT_POST_EVENT_MINUTES
        )
        gap_mins = self.entry.options.get(CONF_GAP_MINUTES, DEFAULT_GAP_MINUTES)
        now = dt_util.now()
        today = now.date()

        parsed: list[dict[str, Any]] = []
        for booking in self.data:
            interval = parse_booking_interval(
                booking, today, dt_util.DEFAULT_TIME_ZONE
            )
            if interval is None:
                continue
            start_dt, end_dt = interval
            # Exact general-occupancy boundaries still update lights/kitchen
            # when this booking explicitly requests no heating.
            if start_dt > now:
                self._schedule_entity_update(start_dt)
            if end_dt > now:
                self._schedule_entity_update(end_dt)
            if not heating_requested(booking):
                continue
            parsed.append(
                {
                    "booking": booking,
                    "space": booking["space"],
                    "start_dt": start_dt,
                    "end_dt": end_dt,
                }
            )

        parsed.sort(key=lambda item: item["start_dt"])

        for index, item in enumerate(parsed):
            booking = item["booking"]
            space = item["space"]
            start_dt = item["start_dt"]
            end_dt = item["end_dt"]
            fire_start = start_dt - timedelta(minutes=pre_mins)
            fire_end = end_dt + timedelta(minutes=post_mins)

            if fire_start > now:
                self._schedule_event(fire_start, EVENT_BOOKING_START, booking)

            skip_end = False
            if gap_mins > 0:
                for next_item in parsed[index + 1 :]:
                    if next_item["space"] != space:
                        continue
                    gap = (next_item["start_dt"] - end_dt).total_seconds() / 60
                    skip_end = 0 <= gap <= gap_mins
                    break

            if not skip_end and fire_end > now:
                self._schedule_event(fire_end, EVENT_BOOKING_END, booking)

    def _schedule_entity_update(self, fire_at: datetime) -> None:
        """Notify coordinator entities at an exact booking boundary."""

        @callback
        def _update(_now: datetime) -> None:
            self.async_update_listeners()

        self._cancel_timers.append(
            async_track_point_in_time(self.hass, _update, fire_at)
        )

    def _schedule_event(
        self, fire_at: datetime, event_name: str, booking: dict[str, Any]
    ) -> None:
        """Schedule one sanitized Home Assistant booking event."""

        @callback
        def _fire(_now: datetime) -> None:
            _LOGGER.info("Firing booking event %s", event_name)
            self.hass.bus.async_fire(
                event_name,
                {
                    "heating_required": heating_requested(booking),
                    "scout_use": booking.get("scout_use", False),
                    "ref": booking.get("ref"),
                    "space": booking.get("space"),
                    "booking_date": booking.get("booking_date"),
                    "start_time": booking.get("start_time"),
                    "end_time": booking.get("end_time"),
                    "attendees": booking.get("attendees"),
                    "purpose": booking.get("purpose"),
                    "kitchen": booking.get("kitchen"),
                    "all_day": booking.get("all_day"),
                },
            )

        self._cancel_timers.append(
            async_track_point_in_time(self.hass, _fire, fire_at)
        )

    def cancel_booking_timers(self) -> None:
        """Cancel all pending booking timers."""
        for cancel in self._cancel_timers:
            cancel()
        self._cancel_timers.clear()

    def cancel_all_timers(self) -> None:
        """Cancel all timers when the config entry unloads."""
        self.cancel_booking_timers()
        for cancel in self._cancel_midnight:
            cancel()
        self._cancel_midnight.clear()
