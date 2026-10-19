# Staff booking entry

## Scope

Add booking in MGF Venue's menu and booking-list toolbar, using the existing admin visual language. One contact/details card, one schedule card and a separate review panel; no separate booking database. One-off, inclusive multi-day date ranges, daily/weekly/fortnightly/monthly/selected-date recurrence use the same domain services as public/API bookings. Recurrence is single-day sessions; multi-day camps remain one continuous record, not daily duplicates.

Stowmarket supports a labelled estate reservation (not an internal Scout night). It remains in All Bookings and has normal area/Whole Park dependency protection. Estate-use scheduling exceptions require an explicit checkbox and explanation; they never override occupied or blocked dates. Staff can explicitly select the coordinator as contact when no external booker exists. This disables customer email. Other bookings require a real named contact/email; staff entry links the booking to that contact's existing account, never the operator's account.

## Review and safety

- Booking-manager/admin capability plus WordPress REST nonce; no public write route.
- Preview performs date, contact, capacity, custom-field, minimum-duration, campsite-policy, pricing and all-occurrence conflict/blocked checks. No booking, invoice, email or Google mutation in preview.
- Server freezes preview for 15 minutes per operator. Commit accepts only that token and explicit confirmation, rechecks the full plan and prices, and rejects changed facts. Browser edits invalidate review. Concurrent/retried commits share an atomic, non-stealing lock and cached outcome; errors after a possible mutation require staff review, never blind retry.
- Notification defaults off; explicit review shows recipient and consequence. Existing normal confirmation/invoice services are reused. No new payment-link operation, no automatic Google enabling, no estate event edits/deletions.
- Paid recurring bookings start pending and are configured/approved via existing series billing tools. No invented terms acceptance and no automatic yearly invoice. Free series can be confirmed using existing approval rules. If a new conflict appears during core recurrence creation, show the exact skipped outcomes rather than concealing a partial series.
- Payment/tier/kitchen controls follow site feature settings. No fake free overrides on paid sites. Custom fields are the existing configured fields. Default event visibility is private.

## Verification / release

Dependency-free policy and render checks, real WP REST preview/commit/nonce/role/idempotency/conflict/paid and campsite scenarios, regression suite and PHP matrix CI. Browser test the form with fictional data, without production submissions. Shared branch/PR; publishing/deployment is a separate operation from building this feature.
