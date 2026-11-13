"""Config flow for Mathlin Booking integration."""
from __future__ import annotations

import asyncio
from typing import Any
from urllib.parse import urlparse

import aiohttp
import voluptuous as vol

from homeassistant import config_entries
from homeassistant.core import callback
from homeassistant.data_entry_flow import FlowResult
from homeassistant.helpers.aiohttp_client import async_get_clientsession

from .const import (
    API_PATH_TODAY,
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
)


def _normalise_url(url: str) -> str:
    """Strip trailing slash and ensure https scheme."""
    url = url.strip().rstrip("/")
    if not url.startswith(("http://", "https://")):
        url = "https://" + url
    return url


async def _test_connection(
    session: aiohttp.ClientSession,
    url: str,
    username: str,
    application_password: str,
) -> str | None:
    """Return None on success, or a config-flow error key on failure."""
    try:
        async with session.get(
            url + API_PATH_TODAY,
            auth=aiohttp.BasicAuth(username, application_password),
            timeout=aiohttp.ClientTimeout(total=10),
        ) as response:
            if response.status == 200:
                payload = await response.json()
                return None if isinstance(payload, list) else "invalid_response"
            if response.status in (401, 403):
                return "invalid_auth"
            if response.status == 404:
                return "plugin_not_found"
            return "cannot_connect"
    except (aiohttp.ClientError, asyncio.TimeoutError):
        return "cannot_connect"
    except Exception:  # noqa: BLE001
        return "unknown"


class MathlinConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    """Handle the Mathlin Booking config flow."""

    VERSION = 1

    async def async_step_user(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Create a new authenticated booking entry."""
        errors: dict[str, str] = {}

        if user_input is not None:
            url = _normalise_url(user_input[CONF_WEBSITE_URL])
            parsed = urlparse(url)
            if not parsed.netloc:
                errors[CONF_WEBSITE_URL] = "invalid_url"
            else:
                error = await _test_connection(
                    async_get_clientsession(self.hass),
                    url,
                    user_input[CONF_USERNAME],
                    user_input[CONF_APPLICATION_PASSWORD],
                )
                if error:
                    errors["base"] = error
                else:
                    await self.async_set_unique_id(parsed.netloc)
                    self._abort_if_unique_id_configured()
                    return self.async_create_entry(
                        title=f"Mathlin – {parsed.netloc}",
                        data={
                            CONF_WEBSITE_URL: url,
                            CONF_USERNAME: user_input[CONF_USERNAME],
                            CONF_APPLICATION_PASSWORD: user_input[
                                CONF_APPLICATION_PASSWORD
                            ],
                        },
                        options={
                            CONF_PRE_EVENT_MINUTES: DEFAULT_PRE_EVENT_MINUTES,
                            CONF_POST_EVENT_MINUTES: DEFAULT_POST_EVENT_MINUTES,
                            CONF_GAP_MINUTES: DEFAULT_GAP_MINUTES,
                        },
                    )

        return self.async_show_form(
            step_id="user",
            data_schema=vol.Schema(
                {
                    vol.Required(
                        CONF_WEBSITE_URL,
                        description={"suggested_value": "https://needhamscouts.uk"},
                    ): str,
                    vol.Required(CONF_USERNAME): str,
                    vol.Required(CONF_APPLICATION_PASSWORD): str,
                }
            ),
            errors=errors,
            description_placeholders={"example": "https://needhamscouts.uk"},
        )

    async def async_step_reauth(
        self, entry_data: dict[str, Any]
    ) -> FlowResult:
        """Start reauthentication for an existing entry."""
        return await self.async_step_reauth_confirm()

    async def async_step_reauth_confirm(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Validate and store replacement WordPress credentials."""
        errors: dict[str, str] = {}
        entry = self._get_reauth_entry()

        if user_input is not None:
            error = await _test_connection(
                async_get_clientsession(self.hass),
                entry.data[CONF_WEBSITE_URL],
                user_input[CONF_USERNAME],
                user_input[CONF_APPLICATION_PASSWORD],
            )
            if error:
                errors["base"] = error
            else:
                return self.async_update_reload_and_abort(
                    entry,
                    data_updates={
                        CONF_USERNAME: user_input[CONF_USERNAME],
                        CONF_APPLICATION_PASSWORD: user_input[
                            CONF_APPLICATION_PASSWORD
                        ],
                    },
                )

        return self.async_show_form(
            step_id="reauth_confirm",
            data_schema=vol.Schema(
                {
                    vol.Required(
                        CONF_USERNAME,
                        default=entry.data.get(CONF_USERNAME, ""),
                    ): str,
                    vol.Required(CONF_APPLICATION_PASSWORD): str,
                }
            ),
            errors=errors,
        )

    @staticmethod
    @callback
    def async_get_options_flow(config_entry: config_entries.ConfigEntry):
        """Return the options flow."""
        return MathlinOptionsFlow(config_entry)


class MathlinOptionsFlow(config_entries.OptionsFlow):
    """Handle booking event timing options."""

    def __init__(self, config_entry: config_entries.ConfigEntry) -> None:
        self.config_entry = config_entry

    async def async_step_init(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Manage booking event timing options."""
        if user_input is not None:
            return self.async_create_entry(title="", data=user_input)

        current = self.config_entry.options
        return self.async_show_form(
            step_id="init",
            data_schema=vol.Schema(
                {
                    vol.Required(
                        CONF_PRE_EVENT_MINUTES,
                        default=current.get(
                            CONF_PRE_EVENT_MINUTES, DEFAULT_PRE_EVENT_MINUTES
                        ),
                    ): vol.All(vol.Coerce(int), vol.Range(min=0, max=480)),
                    vol.Required(
                        CONF_POST_EVENT_MINUTES,
                        default=current.get(
                            CONF_POST_EVENT_MINUTES, DEFAULT_POST_EVENT_MINUTES
                        ),
                    ): vol.All(vol.Coerce(int), vol.Range(min=0, max=480)),
                    vol.Required(
                        CONF_GAP_MINUTES,
                        default=current.get(CONF_GAP_MINUTES, DEFAULT_GAP_MINUTES),
                    ): vol.All(vol.Coerce(int), vol.Range(min=0, max=240)),
                }
            ),
            description_placeholders={
                "pre_hint": "Minutes before booking start (0–480)",
                "post_hint": "Minutes after booking end (0–480)",
            },
        )
