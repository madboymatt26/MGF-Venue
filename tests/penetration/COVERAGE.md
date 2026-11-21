# Penetration test coverage map

Honest statement of what `run-pentest.py` executes against a live disposable
WordPress site, what is only statically inspected, and what is out of scope.
Suite version 2.0 (2026-11-21), 56 checks.

## Executed against a live site (dynamic)

### REST surface
- The plugin registers 55 REST routes from 54 `register_rest_route` call
  sites (one call site is a loop that registers two routes). Of these, 49
  are live web routes and 6 are legacy migration routes registered only
  under WP-CLI / `MBS_ENABLE_LEGACY_MIGRATION` and gated on `manage_options`:
  campsite-migration (x2), campsite-consolidation, campsite-multiday,
  reviewed-park-consolidation, legacy-plugin.
- Sections 1-2 test 22 hardcoded route samples (every `/admin/*` family plus
  the protected `bookings/today`, `bookings/upcoming`, `occupancy-today`
  reads) with both GET and POST, as anonymous and as an authenticated
  subscriber (application-password Basic auth). The remaining 27 live web
  routes are public-by-design feeds (`bookings/calendar`, `bookings/ical`,
  `bookings/date/{date}`, `bookings/{ref}/ical`, hirer auth endpoints) and
  are covered individually by sections 4, 5 and 16.
- Positive controls (section 2+/3): the subscriber identity reaches a public
  route, and an admin application-password identity reads the protected
  bookings collection and sees the fixture booking. Without these, a
  deny-all build would pass the negative checks vacuously.

### Public AJAX (admin-ajax.php)
- `mbs_submit_booking`: missing/forged nonce rejected before throttle
  accounting; first valid submission accepted on a fresh throttle fixture
  (positive control); per-IP throttle engages at the documented bound of 20
  valid submissions/hour (observed: 429 on the 21st).
- `mbs_submit_feedback` and `mbs_submit_modification`: nonce negatives,
  forged-token negatives, cross-purpose token replay negatives (modify token
  cannot submit feedback and vice versa), valid-token positive controls.
- `mbs_get_day` / `mbs_get_blocked_dates`: nonce required; valid-nonce
  responses carry only non-sensitive slot fields.
- `mbs_lookup_booking`: nonce required; wrong-email probes reveal nothing
  for existing or non-existent references.

### Stored XSS (section 8)
- The seed force-writes raw payloads (`<script>`, `<img onerror>`,
  `<svg onload>`) into a fixture booking's name/purpose/notes with a direct
  storage write, bypassing input sanitisation to simulate records that
  predate it.
- The admin booking view (`admin.php?page=mathlin-booking&action=view`) is
  fetched with a genuinely authenticated admin session; the check requires
  the view's own markup (refuting login-page echo), requires the escaped
  payload form to be PRESENT, and requires the raw executable form to be
  ABSENT.
- The guest invoice HTML document for the same booking is fetched with its
  valid scoped token and checked for raw payload absence.

### Guest document delivery (section 12)
- `mbs_invoice_document` negatives: no token, forged token, 64-char forged
  token, valid token with wrong booking ref, and a modify-scoped token
  replayed against the invoice purpose. Positive control: the valid
  invoice-scoped token serves the real PDF.

### Other dynamic checks
- iCal: whole-venue feed leaks no hirer identity; per-booking ical requires
  the scoped calendar token (403 without, 403 forged, 200 text/calendar
  with).
- Date feeds: malformed date shape 404s at the route regex; an impossible
  but well-formed date returns an empty day without leaking; a date with a
  real private booking exposes no identity fields.
- Admin search SQLi probe returns no database error output.
- `admin-post.php` privileged actions (credit accounts, payment expiry,
  additional charges, extras catalogue) reject anonymous and
  authenticated-but-forged-nonce POSTs.
- Direct file access: plugin PHP files are not executable directly;
  no `wp-config.php~` backup.
- Hirer register/login reject forged nonces. WooCommerce order reads reject
  anonymous and forged-credential requests.

## Statically inspected only (not executed)

- Redaction helpers and `MBS_Hirer_Identity::calendar_access` logic
  (independently reviewed in source; the calendar token positive/negative
  controls in section 5 exercise `calendar_access` dynamically).
- The 6 legacy migration routes (WP-CLI / `MBS_ENABLE_LEGACY_MIGRATION`
  gated, `manage_options`-only): static review only, not executed.
- No file-upload handler exists in the plugin, so there is no upload attack
  surface to test.
- SQL: all plugin queries use `$wpdb->prepare` or table/literal
  interpolation of fixed strings (verified statically; the dynamic SQLi
  probes in sections 4, 7 and 9 found no injection behaviour).
- No unsafe execution sinks (`eval`, `exec`, `system`, `popen`,
  `call_user_func` on request input) exist in the plugin.
- Scoped token implementation (`MBS_Bookings::scoped_token` /
  `verify_scoped_token`): purpose-bound HMAC with optional TTL and a
  constant-time compare; exercised dynamically via the positive and
  cross-purpose controls in sections 12-14.
- PowerShell deployment/maintenance scripts in the repo: static review only.
- Campsite-mode route variants (behind `MBS_Campsite_Policy`): not executed;
  the campsite policy layer is disabled on the test site.

## Out of scope

- The live venue site, real payment providers, real mail delivery, and any
  third-party service. The suite runs only against a disposable
  127.0.0.1/localhost integration site with synthetic fixtures and a
  deterministic test payment gateway.
- WordPress core, WooCommerce internals and the web server/PHP stack
  themselves, beyond the authentication-boundary checks in section 17.

## Known limits

- A 56/56 run means the 56 tested checks passed on this build; it is not a
  guarantee that no vulnerability exists. Surfaces listed under
  "statically inspected only" carry the usual limits of source review.
- The subscriber checks use one subscriber identity; role/capability
  combinations beyond `subscriber` and `manage_options` are not exercised.
