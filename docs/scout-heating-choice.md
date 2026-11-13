# Scout booking heating choice — implementation and rollout contract

## 13 November rollout stop: general occupancy is not heating demand

3.47.0 passed all ten CI checks and was briefly deployed to Needham, then rolled
back to 3.46.0. It is now a prerelease, not the latest stable update. Operational
booking/payment/email/settings fingerprints were unchanged across both operations.
The installed original HA coordinator was restored and HA restarted. The new
helper remains on disk but is not imported by the original coordinator.

Live dependency inspection showed the same HA occupancy and kitchen sensors also
drive oven/hob and lighting automations. Filtering those entities would erase
unheated Scout one-offs from non-heating controls. The corrected design must keep
existing general-occupancy entities intact and supply separate heating-demand
entities/feeds to heating controllers (including ASHP and heating forecasts).
Do not activate the current heating-only consumer as complete venue occupancy.
The two approved overlap guards remain live; schedules and setpoints are unchanged.

Not deployed. Do not install the repository's old HA component over the live HQ
component: Chrome verified live version 2.0.0 on 9 November 2026, while this
checkout contains 1.1.0. The installed coordinator was downloaded read-only and
used to prepare `ha-integration/patches/2.0.0/coordinator.py`; it retains its
authenticated five-minute polling and successful-refresh timer rebuilding.
This targeted replacement also needs `heating_policy.py` from the component
source directory copied alongside it. Do not replace the rest of live 2.0.0
with the older component folder. Full 2.0.0 source reconciliation remains open.

## Policy

- Existing and new Scout series do not request plugin-managed heating. Their
  separate HA schedules remain authoritative and must not be edited or duplicated.
- One-off Scout bookings default off. A booking manager can save an explicit
  preference from the booking detail page, including after creating a booking.
- External hires keep their existing heating eligibility. Inactive bookings never
  appear in heating feeds. Approval/payment/access rules are unchanged.
- Choice is private operational metadata, not a financial change or invoice
  revision. The dedicated AJAX handler requires manager permission, nonce, complete
  booking fingerprint, occupancy lock and the existing transactional change service.
- Existing generic edits preserve this metadata. Customer custom-field validation
  does not accept a raw internal boolean. Series policy wins over copied metadata.

## Producer/consumer contract

The authenticated `/bookings/today` and `/bookings/upcoming` HA feeds exclude
Scout opt-outs and Scout series. Generic occupancy, availability, public calendar
and iCal sources retain those bookings. HA payloads expose `scout_use`, boolean
`heating_required`, actual `booking_status` and `integration_scope=booking_heating`.

An approved booking with a disabled request produces `heating_request_removed`,
not `booking_cancelled`. Actual inactive status still produces cancellation.
Workers re-read current eligibility; older queued enable requests cannot override
a subsequent opt-out. A successful HTTP response is transport acceptance only.

The repository legacy component defensively filters explicit opt-outs before
publishing its model or scheduling timers. This is not proof that live 2.0.0
behaves identically. Current HA must handle removal by refreshing the model and
discarding that reference's pending timers, without any customer notification or
global climate shutdown. Same-day changes require bounded authenticated refresh,
not only the legacy midnight poll.

## Release gates still required

1. Actual controllers inspected and backed up. Fresh 13 November UI reads confirm
   the two target controllers and ASHP target controller match the reviewed backup.
   Both shared-demand guards were saved on 13 November and survived UI reload.
   Scout days, attendance conditions, temperatures and the ASHP controller remain
   unchanged. A natural booking-controller trace evaluated all demand flags
   successfully without issuing heating commands.
2. Verify the prepared 2.0.0 patch against its installed dependencies without
   downgrading authentication or interval handling. Verify
   current credentials privately; never copy browser tokens into the repository.
3. Verify a shared demand arbiter: ending/removing a booking cannot reduce heating
   required by an independent Scout schedule or another overlapping zone demand.
   The repository example global shutdown/failsafe YAML is NOT safe evidence of
   this. Do not install those examples as a production replacement.
4. Synthetic tests: defaults, approval, opt-in/out, queued stale delivery, retry,
   cancellation/delete, refresh failure, restart, DST/overnight/ongoing interval,
   shared/parent spaces, neighboring bookings and independent Scout schedules.
5. Real SQL rollback/auth/CAS and browser keyboard/narrow-layout acceptance; full
   existing regression suite. Back up both installed components and compare live
   booking/payment/email/settings fingerprints before and after a reviewed rollout.
6. Observe scheduling without firing physical heat commands. No bulk enabling,
   production test booking, customer email, payment change or HA schedule mutation.

The Drain Doctor maintenance booking must remain off. No production choice has
been written by this development work.

## Local evidence

- `tests/php-scout-heating-choice.php`: 20 new assertions exercising real feed,
  policy, queue and worker code with synthetic adapters, in addition to the
  existing 18 review-reliability assertions.
- `tests/php-scout-heating-admin.php`: 16 handler assertions covering nonce,
  permission, stale version, explicit boolean, one-off scope, inactive records,
  malformed metadata, lock release and nonfinancial/non-notifying service options.
  This is an isolated adapter test, not real SQL rollback certification.
- `tests/python-heating-policy.py`: six pure Python contract tests.
- `tests/python-heating-coordinator.py`: six synthetic coordinator tests covering
  poll removal, duplicate notifications, setup idempotency, replacement, failed
  refresh preservation and unload. Regular HA polling uses listener notification,
  not the push-only setter; changed successful data rebuilds timers, including
  removal to an empty list. Failed refreshes preserve the last valid timers.
- Both legacy and prepared 2.0.0 coordinators compile. This is not a Home
  Assistant runtime integration test or independent-schedule arbitration test.
- Seven synthetic shared-demand tests pass: room isolation, preheat boundary,
  removal, overlapping kitchen demand, unavailable occupancy and original Scout
  attendance cutoff. The guard builder refuses changed stop inventories.
- The 2.0.0 patch normalises the site's Whole Centre label to the installed HA
  Whole Headquarters alias, retaining venue_space; stored WP spaces are unchanged.
- All 87 PHP programs pass with a writable test temporary directory. The first
  sandbox run had a temporary-file permission failure, not an updater regression.
- Real SQL acceptance passed 23 assertions, including forced outbox failure
  rollback. Browser acceptance passed opt-in/out persistence, stale-save refusal,
  keyboard control, 390px layout and series/external read-only policy. It caught
  and corrected a hidden action input shadowing the form action URL.
- Final exact-head CI, producer deployment, targeted HA component installation
  and live refresh verification remain release gates. Do not equate pure Jinja
  tests with a physical heating test.
