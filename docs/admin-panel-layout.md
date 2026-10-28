# Admin panel presentation follow-up

Implemented for review; not a claim of publication or production installation.

## Scope

- Use the existing Scout-purple/white card system for Needs attention, the reusable extras disclosure and the Add booking sections. Shared card-body padding replaces inconsistent inline padding and direct-child-only rules that left nested forms flush to the edge.
- Keep native `details` / `summary` behaviour, with a themed header, visible keyboard focus and a reduced-motion-aware disclosure indicator. No custom collapse JavaScript or approval behaviour.
- Replace grey WordPress-style section tabs with a scoped white navigation bar and purple active tab. Existing links, selected-section logic and accessible current-page labels remain unchanged.
- Add booking explicitly uses two equal `minmax(0,1fr)` columns with top alignment, one column at 1000px and below, consistent text/email/telephone heights, bounded widths and box sizing. Cards cannot retain a lower-specificity host max-width or oversized middle gap. The empty left card is not stretched to the height of the longer right card.
- Standard venue copy refers to hirers and Scout groups, not estate bookings; campsite wording and fields remain profile-specific. Existing field IDs, names, values, validation, preview/commit handlers, notification opt-in and paid/free rules are retained. Default text fields now declare their equivalent `type="text"` explicitly.
- Pad the operational settings form and Google/payment feature panels consistently. Preserve all form actions, nonce/CAS fields, save handlers and controls. Space and tier editors scroll within their own labelled regions instead of being clipped by the card on narrow screens.
- Keep pagination with the booking-list controls rather than above its summary metrics. Existing page/search/status links are unchanged.

## Verification / limits

- Dependency-free `tests/php-admin-panel-markup.php` checks native themed disclosures, original catalogue forms, no nested forms, unchanged disabled/permission guards and attention reference content.
- `tests/admin-panel-layout-browser.cjs` is an optional isolated localhost-only development browser test. Covers 1920/1280/900/390 widths, both venue and free campsite/multi-area profiles, form gutters, consistent control heights, table containment, host-style collision fixtures, native keyboard collapse/expand and notification opt-in. Saves synthetic screenshots only when `MBS_SCREENSHOT_DIR` is set. No booking/save forms are submitted.
- Run the existing PHP and JavaScript suites and real WordPress booking-entry scenarios before release. This change does not modify a schema, saved settings, bookings, invoices, payments, customer mail or calendar events.
- Live Chrome inspection was blocked by an open extension panel. Do not claim the real site-specific computed-style cause of the screenshot's oversized gap was observed. The source inconsistencies were verified, and defensive layout rules are exercised in an isolated real WordPress renderer. A live visual check after the normal reviewed deployment remains appropriate.

Local acceptance: 81 PHP test programs; 245 first-party PHP syntax checks; four existing JavaScript suites; 22 panel-markup assertions; 195 real WordPress navigation assertions and the staff booking-entry integration scenario. Browser acceptance passed 66 paid-venue and 60 campsite/multi-area assertions at four widths. Screenshots of both profiles and the extras panel were inspected. No production deployment or data changes were used for verification.
