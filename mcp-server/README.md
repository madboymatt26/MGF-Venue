# MGF Venue MCP bridge

This directory contains a dependency-free Windows STDIO MCP server that exposes a deliberately narrow set of MGF Venue administration tools to Codex.

It calls the authenticated WordPress REST API at `/wp-json/mathlin/v1/admin/...`. Install the matching WordPress plugin version before connecting the MCP server.

## Tools

- `list_bookings` — filter and paginate bookings.
- `get_booking` — retrieve one current booking.
- `check_availability` — check blocked dates and booking conflicts.
- `get_booking_audit` — retrieve the booking action history without stored IP addresses.
- `set_booking_status` — idempotently set pending, confirmed, or cancelled; external email is opt-in.
- `update_admin_notes` — replace the private administrator note without emailing the hirer.
- `list_series` / `get_series` — read first-class or registered legacy series,
  occurrences, exceptions, previews, invoices and audit without token/hash fields.
- `approve_series` — approve with expected status/version and a stable
  idempotency key; customer confirmation is opt-in.
- `configure_series_billing` — configure monthly/termly/upfront/legacy/no-charge
  billing and online/offline payment. Legacy adoption must be explicit.
- `update_series_state` — pause, resume, extend or cancel future/all dates with
  optimistic concurrency; customer email is opt-in.
- `list_invoices` / `get_invoice` — read safe invoice, item and transaction data.
- `record_invoice_payment` — record exact-minor-unit offline payments with
  invoice version and idempotency protection.

- `get_admin_resource` reads blocked dates, series, change requests, invoices,
  payment links, and redacted plugin/email/custom-field/OSM configuration.
- `run_admin_action` invokes the same strictly allow-listed handler used by the
  MGF Venue web admin for booking, payment, series, communication, blocked-date,
  request, settings, custom-field, Home Assistant and OSM actions.
- `export_admin_data` saves the web admin's booking/accounting exports to an
  explicit local CSV path.

The generic admin action tool is deliberately marked destructive so Codex asks
for approval. The WordPress handler still performs its normal capability and
input checks. Stored access codes, tokens, webhooks and OSM client credentials
are never returned by read tools; configuration reads expose only whether those
secrets are configured.

The WordPress API response allow-lists deliberately exclude
`modification_token`, invoice bearer tokens, payment-token hashes, financial
idempotency hashes and any future database fields unless explicitly reviewed.

## WordPress authentication

1. Create or choose a dedicated WordPress user for the integration.
2. Assign only the MGF Venue booking-manager capability (`mbs_manage_bookings`) unless an administrator-only endpoint is genuinely required.
3. In that user's WordPress profile, create an Application Password named `MGF Venue MCP`.
4. Store the generated password in the `MGF_VENUE_APP_PASSWORD` environment variable. Do not put it in this repository or `config.toml`.

Set these three user-level environment variables on the Windows machine running Codex:

```powershell
[Environment]::SetEnvironmentVariable('MGF_VENUE_BASE_URL', 'https://needhamscouts.uk', 'User')
[Environment]::SetEnvironmentVariable('MGF_VENUE_USERNAME', '<wordpress-username>', 'User')
[Environment]::SetEnvironmentVariable('MGF_VENUE_APP_PASSWORD', '<wordpress-application-password>', 'User')
```

Restart Codex after setting or changing environment variables.

## Codex configuration

Codex supports local STDIO MCP servers configured in `~/.codex/config.toml` or a trusted project's `.codex/config.toml`. Add the following, replacing the script path with the installed absolute path:

```toml
[mcp_servers.mgf_venue]
command = "powershell.exe"
args = ["-NoLogo", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", "C:\\path\\to\\MGF-Venue\\mcp-server\\mgf-venue-mcp.ps1"]
env_vars = ["MGF_VENUE_BASE_URL", "MGF_VENUE_USERNAME", "MGF_VENUE_APP_PASSWORD"]
default_tools_approval_mode = "writes"
startup_timeout_sec = 15
tool_timeout_sec = 60
enabled = true
```

The `writes` approval mode allows read tools normally while prompting for
booking/series status, billing and payment writes.

After saving the configuration, restart Codex and use `/mcp` to verify that `mgf_venue` is connected.

## Local protocol check

This check does not contact WordPress or require credentials:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\mcp-server\mgf-venue-mcp.ps1 -SelfTest
```

For a complete end-to-end check, configure a non-production WordPress user and call the read-only tools before enabling writes.
