"""Constants for the Mathlin Booking integration."""

DOMAIN = "mathlin_booking"
PLATFORMS = ["sensor"]

# Config entry keys
CONF_WEBSITE_URL = "website_url"

# Options keys (configurable after setup)
CONF_PRE_EVENT_MINUTES  = "pre_event_minutes"   # fire start event N mins before booking
CONF_POST_EVENT_MINUTES = "post_event_minutes"  # fire end event N mins after booking ends

# Defaults
DEFAULT_PRE_EVENT_MINUTES  = 60   # 1 hour before
DEFAULT_POST_EVENT_MINUTES = 15   # 15 minutes after end

# REST API path (appended to the website URL)
API_PATH_TODAY    = "/wp-json/mathlin/v1/bookings/today"
API_PATH_UPCOMING = "/wp-json/mathlin/v1/bookings/upcoming"

# HA event names fired by this integration
EVENT_BOOKING_START = "mathlin_booking_start"
EVENT_BOOKING_END   = "mathlin_booking_end"

# Sensor attributes
ATTR_REF        = "ref"
ATTR_SPACE      = "space"
ATTR_START_TIME = "start_time"
ATTR_END_TIME   = "end_time"
ATTR_ATTENDEES  = "attendees"
ATTR_PURPOSE    = "purpose"
ATTR_KITCHEN    = "kitchen"
ATTR_ALL_DAY    = "all_day"
ATTR_DATE       = "booking_date"
