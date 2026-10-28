# Grouped administration navigation

Implementation only; not a claim of publication or live installation.

## Sidebar and destinations

| Sidebar | Section links |
| --- | --- |
| Bookings | All bookings, Recurring series, Scout nights (when enabled), Archived |
| Add booking | Independent entry, immediately below Bookings |
| Calendar | Calendar, Blocked dates |
| Requests | Change requests; existing pending-count badge retained |
| Finance (paid) / Reports (free) | Reports; Additional charges and OSM only when their existing feature/capability checks allow |
| Settings | General, Email templates, Custom fields, Google Calendar sync |
| Help & health | Staff guide, Integration health (administrator only), Audit log |

The top-level Bookings badge continues to count pending bookings. This navigation does not combine change requests with pending booking approvals or alter either workflow. No new organisation, equipment, billing or cross-site feature is introduced.

## Compatibility and safety

- Keep every original page slug, callback hook, capability, parent registration and page title. Do not redirect requests or alter query parameters/forms/nonces.
- WordPress resolves plugin hooks through the submenu table. Secondary entries must remain in that table, with a scoped presentation-only CSS class hiding their sidebar rows. Do not remove/re-parent the entries merely to shorten the menu: this breaks old routes or changes capability resolution.
- Capture registered pages after modules register their menus. Do not recreate feature-disabled pages. Unknown extension pages stay visible rather than silently disappearing.
- Group selection uses the validated WordPress `$plugin_page`, not an arbitrary requested destination. Section links use fixed existing GET destinations and repeat capability checks. Navigation cannot grant permission.
- Payment-disabled sites keep non-financial usage reports. Google Calendar sync configuration remains administrator-only regardless of payment state. Existing handler-level guards remain authoritative.
- Navigation has no database schema, option, booking, invoice, payment, mail, audit or Google-event writes. No booking approval, sync or repair runs while navigating.
- Use native links and wrapping WordPress tabs, with an accessible navigation label, `aria-current="page"`, visible keyboard focus, and no JavaScript dependency. Staff guide explains the new locations.

## Verification

- `php tests/php-admin-navigation.php`: dependency-free paid/free, administrator/manager/no-permission, badges, disabled features, unknown extension, current section and escaping checks.
- `wp eval-file tests/integration/scenarios/admin-navigation.php`: disposable WordPress only; real core parent/hook/access resolution across five role/profile combinations, preserving exact registrations. Added to hosted runtime matrix.
- `node tests/admin-navigation-browser.cjs`: optional isolated localhost browser acceptance. Requires Playwright, `MBS_TEST_PASSWORD` for a synthetic local administrator, and optionally `MBS_TEST_BROWSER_CHANNEL=chrome`, `MBS_TEST_PAID=0`, `MBS_SCREENSHOT_DIR`. Checks seven visible sidebar entries, original grouped routes, active section, keyboard navigation and responsive wrapping. Does not submit forms. Never point at a production host.
- Run the existing PHP suite, first-party syntax checks and JavaScript regressions before release. Publication/deployment requires its normal reviewed release and per-site read-only checks; this file does not establish completion of those steps.
