"""Config flow for Mathlin Booking integration."""
from __future__ import annotations

import logging
from typing import Any
from urllib.parse import urlparse

import aiohttp
import voluptuous as vol

from homeassistant import config_entries
from homeassistant.core import callback
from homeassistant.data_entry_flow import FlowResult

from .const import (
    DOMAIN,
    CONF_WEBSITE_URL,
    CONF_PRE_EVENT_MINUTES,
    CONF_POST_EVENT_MINUTES,
    CONF_GAP_MINUTES,
    DEFAULT_PRE_EVENT_MINUTES,
    DEFAULT_POST_EVENT_MINUTES,
    DEFAULT_GAP_MINUTES,
    API_PATH_TODAY,
)

_LOGGER = logging.getLogger(__name__)


def _normalise_url(url: str) -> str:
    """Strip trailing slash and ensure https scheme."""
    url = url.strip().rstrip("/")
    if not url.startswith(("http://", "https://")):
        url = "https://" + url
    return url


async def _test_connection(url: str) -> str | None:
    """
    Try to reach the booking API.
    Returns None on success, or an error string key on failure.
    """
    test_url = url + API_PATH_TODAY
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(test_url, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                if resp.status == 200:
                    return None
                if resp.status == 404:
                    return "plugin_not_found"
                return "cannot_connect"
    except aiohttp.ClientConnectorError:
        return "cannot_connect"
    except Exception:  # noqa: BLE001
        return "unknown"


class MathlinConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    """Handle the initial setup config flow."""

    VERSION = 1

    async def async_step_user(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        errors: dict[str, str] = {}

        if user_input is not None:
            url = _normalise_url(user_input[CONF_WEBSITE_URL])

            # Basic URL validation
            parsed = urlparse(url)
            if not parsed.netloc:
                errors[CONF_WEBSITE_URL] = "invalid_url"
            else:
                error = await _test_connection(url)
                if error:
                    errors[CONF_WEBSITE_URL] = error
                else:
                    # Prevent duplicate entries for the same site
                    await self.async_set_unique_id(parsed.netloc)
                    self._abort_if_unique_id_configured()

                    return self.async_create_entry(
                        title=f"Mathlin – {parsed.netloc}",
                        data={CONF_WEBSITE_URL: url},
                        options={
                            CONF_PRE_EVENT_MINUTES:  DEFAULT_PRE_EVENT_MINUTES,
                            CONF_POST_EVENT_MINUTES: DEFAULT_POST_EVENT_MINUTES,
                            CONF_GAP_MINUTES:        DEFAULT_GAP_MINUTES,
                        },
                    )

        return self.async_show_form(
            step_id="user",
            data_schema=vol.Schema({
                vol.Required(
                    CONF_WEBSITE_URL,
                    description={"suggested_value": "https://needhamscouts.uk"},
                ): str,
            }),
            errors=errors,
            description_placeholders={
                "example": "https://needhamscouts.uk",
            },
        )

    @staticmethod
    @callback
    def async_get_options_flow(config_entry: config_entries.ConfigEntry):
        return MathlinOptionsFlow(config_entry)


class MathlinOptionsFlow(config_entries.OptionsFlow):
    """Handle options — configurable after setup."""

    def __init__(self, config_entry: config_entries.ConfigEntry) -> None:
        self.config_entry = config_entry

    async def async_step_init(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        if user_input is not None:
            return self.async_create_entry(title="", data=user_input)

        current = self.config_entry.options

        return self.async_show_form(
            step_id="init",
            data_schema=vol.Schema({
                vol.Required(
                    CONF_PRE_EVENT_MINUTES,
                    default=current.get(CONF_PRE_EVENT_MINUTES, DEFAULT_PRE_EVENT_MINUTES),
                ): vol.All(vol.Coerce(int), vol.Range(min=0, max=480)),
                vol.Required(
                    CONF_POST_EVENT_MINUTES,
                    default=current.get(CONF_POST_EVENT_MINUTES, DEFAULT_POST_EVENT_MINUTES),
                ): vol.All(vol.Coerce(int), vol.Range(min=0, max=480)),
                vol.Required(
                    CONF_GAP_MINUTES,
                    default=current.get(CONF_GAP_MINUTES, DEFAULT_GAP_MINUTES),
                ): vol.All(vol.Coerce(int), vol.Range(min=0, max=240)),
            }),
            description_placeholders={
                "pre_hint":  "Minutes before booking start to fire the 'booking_start' event (0–480)",
                "post_hint": "Minutes after booking end to fire the 'booking_end' event (0–480)",
            },
        )
