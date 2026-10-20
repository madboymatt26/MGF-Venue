# Access details policy

Access delivery uses the installation's effective Payments and billing switch, not the booking amount, campsite hostname or a pricing-tier exemption. With payments off, confirmed/deposit-paid/paid bookings qualify. With payments on, only paid bookings qualify. Pending, cancelled, archived and missing bookings never qualify, including manual sends.

The normal 24-hour setting means the previous calendar day from 08:00 in the WordPress timezone. An hourly cron check permits catch-up after late approval/payment, only before the booking starts. Other configured hour values retain an elapsed-hours window. Site traffic is not an exact scheduler: configure server cron for reliable delivery.

Automatic access mail uses the transactional outbox with a stable booking/date/area/contact snapshot key. No access code is committed to source or included in that queued placeholder. The worker rechecks current approval, payment switch, recipient, dates, code and access-enabled flag before every send, rendering the current code only at dispatch. Cancelled/changed/expired source messages are blocked; policy pauses do not consume SMTP retries. The existing queue is at-least-once under process failure, not an absolute exactly-once guarantee. Sent flags mean transport acceptance, not verified inbox delivery.

The manual send button uses the same approval/payment policy, but intentionally bypasses the automatic time window. Explicit manual resends have distinct queue keys.

Administrator MCP action save_settings accepts a narrow access_settings object with enabled, code, instructions, health_safety, hours_before. Omitted fields and all unrelated settings are preserved. The response returns only a code-configured boolean, never the code. No installation is enabled automatically by this release. Migration-set access_sent flags remain untouched; deciding whether to include future imported bookings is a separate reviewed operation.
