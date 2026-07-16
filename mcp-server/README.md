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

The WordPress API response allow-list deliberately excludes `modification_token` and any future database fields unless they are explicitly reviewed and added.

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

The `writes` approval mode allows read tools normally while prompting for the status and note tools.

After saving the configuration, restart Codex and use `/mcp` to verify that `mgf_venue` is connected.

## Local protocol check

This check does not contact WordPress or require credentials:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\mcp-server\mgf-venue-mcp.ps1 -SelfTest
```

For a complete end-to-end check, configure a non-production WordPress user and call the read-only tools before enabling writes.
