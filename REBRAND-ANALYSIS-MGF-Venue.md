# Rebrand Analysis: "Mathlin Booking System" → "MGF Venue"

**Status:** Read-only analysis. No files changed.
**Plugin version at time of audit:** 3.13.1
**Author:** System architecture review
**Scope:** `wp-plugin/mathlin-booking/` (WordPress plugin) + `ha-integration/` (Home Assistant custom component)

---

## Executive Summary

The old branding is expressed through **five distinct naming conventions**, not one. A clean rebrand is not a find-and-replace; the identifiers fall into two fundamentally different risk classes:

| Class | Examples | Safe to change? |
|---|---|---|
| **Cosmetic** (text shown to humans) | "Scout Bookings" menu title, email headings, plugin description | ✅ Yes — zero runtime risk |
| **Structural** (persisted keys + external contracts) | `mbs_*` options, `mathlin_*` tables, `mbs_daily_*` crons, `mathlin/v1` REST namespace, `_mbs_booking_ref` order meta, HA webhook ID + event names | ⚠️ No — renaming without migration causes data loss or silent breakage on the live site |

**Naming conventions in use:**
1. `MBS_` — PHP class prefix and `MBS_VERSION` / `MBS_TABLE` / `MBS_PLUGIN_*` constants
2. `mbs_` — option keys, AJAX actions, cron hooks, nonces, user-meta, WooCommerce cart/order meta, CSS IDs/JS handles
3. `mathlin_` — DB table prefix, REST namespace, shortcodes, HA webhook id, HA event names
4. `nms-` — frontend CSS class prefix (NMS = Needham Market Scout)
5. `MBS-` — booking reference prefix (e.g. `MBS-ABC123`) baked into every existing record

---

## STEP 1 — Codebase & Dependency Audit

### 1.1 File & Folder Structures

| Item | Value | Notes |
|---|---|---|
| Plugin folder | `wp-plugin/mathlin-booking/` | This is the **plugin slug** — directory name = active plugin identity in WP |
| Main file | `mathlin-booking.php` | Filename forms the plugin basename `mathlin-booking/mathlin-booking.php` |
| Class files | `includes/class-*.php` (28 files) | Filenames are neutral (`class-bookings.php` etc.) — **no rename needed**; only the `MBS_` class names inside |
| HA component | `ha-integration/custom_components/mathlin_booking/` | External — folder name is the HA integration **domain** |

PHP class files do **not** need renaming on disk; they are loaded by explicit `require_once`, not autoloading. Only the class *names* carry branding.

### 1.2 Database & Meta Keys

**Custom tables** (`$wpdb->prefix . 'mathlin_...'`):
- `wp_mathlin_bookings` (core — `MBS_TABLE` constant = `'mathlin_bookings'`)
- `wp_mathlin_blocked_dates`
- `wp_mathlin_audit_log`
- `wp_mathlin_email_queue`
- `wp_mathlin_mod_requests`

**Options** (`wp_options`, `mbs_` prefix — ~45 keys). Representative set:
`mbs_db_version`, `mbs_spaces`, `mbs_kitchen_price`, `mbs_admin_email`, `mbs_additional_emails`, `mbs_min_notice_days`, `mbs_ha_webhook_url`, `mbs_org_name`, `mbs_org_address`, `mbs_org_phone`, `mbs_org_charity_number`, `mbs_org_logo_url`, `mbs_github_token`, `mbs_bank_sort_code`, `mbs_bank_account_number`, `mbs_bank_account_name`, `mbs_deposit_enabled`, `mbs_deposit_percentage`, `mbs_deposit_balance_days`, `mbs_pricing_tiers`, `mbs_venue_capacity`, `mbs_curfew_saturday`, `mbs_curfew_sunday`, `mbs_payment_days_required`, `mbs_offline_payment_instructions`, `mbs_terms_text`, `mbs_terms_page_id`, `mbs_booking_notice`, `mbs_facilities_text`, `mbs_access_enabled`, `mbs_access_code`, `mbs_access_instructions`, `mbs_access_hours_before`, `mbs_access_health_safety`, `mbs_feedback_enabled`, `mbs_feedback_review_url`, `mbs_feedback_subject`, `mbs_feedback_body`, `mbs_feedback_distribution_email`, `mbs_auto_chase_enabled`, `mbs_auto_archive_days`, `mbs_reminder_hours`, `mbs_max_chase_emails`, `mbs_chase_interval_days`, `mbs_cron_time_*`, `mbs_email_template_*` (one per template type).

**User meta:**
- `mbs_pricing_tier` — assigned pricing tier per WP user
- `mbs_scout_volunteer` — scout volunteer flag

**WooCommerce cart/order meta** (live payment contract):
- Cart: `mbs_booking_ref`, `mbs_booking_amount`, `mbs_payment_type`
- Order item: `_mbs_booking_ref`
- Order: `_mbs_booking_ref`, `_mbs_payment_processed`

> **Note on the brief's assumption:** the task references "post meta (like our Scout Use flags)". This is inaccurate for this codebase — Scout Use is the `scout_use` **column** on `wp_mathlin_bookings`, not post meta. The plugin uses **custom tables, not CPTs**. The only post meta touched is on WooCommerce orders (above). This distinction matters for the migration strategy.

### 1.3 Hooks, Functions & Cron Events

**Cron events** (`mbs_` prefix — registered via `wp_schedule_event`, fired by name):
| Hook | Schedule | Class |
|---|---|---|
| `mbs_daily_reminders` | daily 07:00 | MBS_Reminders |
| `mbs_daily_access_details` | daily 08:00 | MBS_Access_Details |
| `mbs_daily_payment_chase` | daily 09:00 | MBS_Payment_Chaser |
| `mbs_daily_feedback` | daily 10:00 | MBS_Feedback |
| `mbs_daily_auto_archive` | daily 02:00 | MBS_Auto_Archive |
| `mbs_process_email_queue` | hourly | MBS_Email_Queue |

Cron hook names are stored in the `cron` option keyed by hook name. Renaming the string without re-scheduling **orphans** the old event and silently stops that job.

**AJAX actions** (~40, `wp_ajax_mbs_*` / `wp_ajax_nopriv_mbs_*`) — must stay in lockstep with the JS that calls them (`admin.js`, `public.js`, inline scripts). Examples: `mbs_submit_booking`, `mbs_get_calendar`, `mbs_lookup_booking`, `mbs_save_settings`, `mbs_resend_access`, `mbs_send_feedback_request`, `mbs_submit_feedback`, `mbs_export_csv`, `mbs_export_accounting`, etc.

**Nonces:** `mbs_public_nonce`, `mbs_admin_nonce`. Internal — low risk if changed atomically on both PHP + JS sides.

**Capability:** `mbs_manage_bookings` (custom cap for the "Booking Manager" role) — checked via `current_user_can( 'mbs_manage_bookings' )`. This is **granted to roles stored in the DB**; renaming the cap string silently strips access from existing Booking Manager users.

**Constants:** `MBS_VERSION`, `MBS_PLUGIN_DIR`, `MBS_PLUGIN_URL`, `MBS_TABLE`.

**Admin menu slugs:** `mathlin-booking` (parent), `mathlin-scout-nights`, `mathlin-calendar`, `mathlin-archived`, `mathlin-blocked`, `mathlin-settings`, `mathlin-emails`, `mathlin-custom-fields`, `mathlin-osm`, `mathlin-analytics`, `mathlin-requests`, `mathlin-audit-log`. Also note `enqueue_assets()` gates asset loading on `strpos( $hook, 'mathlin' )` — change the slugs and asset loading breaks unless that guard changes too. Bookmarked admin URLs (`?page=mathlin-booking&...`) appear in emails and audit links.

### 1.4 External Integrations (highest blast radius)

**Home Assistant** — the integration is a *separate deployed artifact* on the HA box; the WP plugin cannot update it. Coupling points:
- **REST namespace** `mathlin/v1` — HA polls `https://needhamscouts.uk/wp-json/mathlin/v1/bookings/today` and `/upcoming`. Hard-coded in `ha-integration/configuration.yaml` and the custom component.
- **Webhook id** `mathlin_booking` — `MBS_HomeAssistant::notify()` POSTs to the configured webhook URL; the HA automations trigger on `webhook_id: mathlin_booking`.
- **Webhook payload `event` values** `booking_confirmed` / `booking_cancelled` — consumed by HA automations.
- **HA-fired events** `mathlin_booking_start` / `mathlin_booking_end` — these are fired *inside HA* by the custom component and consumed by `automations.yaml`. The WP plugin doesn't fire them, but they share the brand and live in the same repo.
- **HA integration domain** `mathlin_booking` (folder + `DOMAIN`) — renaming requires removing/re-adding the integration in HA and migrating entity IDs (`binary_sensor.scout_hall_occupied`, `sensor.hall_bookings_today`).

**WooCommerce** — payment flow keyed on `_mbs_booking_ref` order item/order meta and `mbs-booking-payment` product slug (`PRODUCT_SLUG`). In-flight/unpaid orders created before a rename would have old-key meta; the `on_order_completed` / `on_order_refunded` handlers read those keys to reconcile payment → booking status. Mismatch = payments that don't mark bookings paid.

**GitHub auto-updater** (`class-updater.php`) — hard-codes:
- repo `madboymatt26/mathlin-booking`
- `repo_subdir = 'wp-plugin/mathlin-booking'`
- `slug = 'mathlin-booking'`
- plugin basename `mathlin-booking/mathlin-booking.php`
- `fix_source_dir()` explicitly looks for `mathlin-booking.php` and renames the extracted dir to `mathlin-booking/`.

Renaming the plugin folder/main file is the single most dangerous change for *update continuity*: WordPress identifies a plugin by its basename. Change it and WP treats it as a **different plugin** — the old one stays "installed but inactive", the updater won't match, and you can get duplicate installs. This is the same class of bug that previously caused the 3.3.x infinite-update loops.

### 1.5 Shortcodes (live on production pages)

Every shortcode below is likely placed on a published page; the page content stores the literal tag, so a renamed shortcode renders as **empty** until each page is edited:

| Shortcode | Purpose |
|---|---|
| `[mathlin_booking]` | Full calendar + booking form |
| `[mathlin_calendar]` | Read-only calendar |
| `[mathlin_status]` | Status lookup + modification |
| `[mathlin_modify]` | Modification form |
| `[mathlin_manage]` | Unified manage page |
| `[mathlin_portal]` | Hirer login/dashboard (registered in MBS_Hirer_Portal) |
| `[mathlin_terms]` | T&Cs |
| `[mathlin_venue_info]` | Venue info/pricing |
| `[mathlin_feedback]` | Private feedback form (v3.13.0) |

Several are also discovered by `get_posts( 's' => 'mathlin_...' )` page-search lookups (portal/terms/booking/venue_info/feedback URL resolution). Those search strings must match the shortcode names.

**Frontend JS globals:** `NMS` (public localize handle), `MBS_Admin` (admin localize handle), CSS `nms-*` classes, asset handles `mbs-public` / `mbs-admin`.

---

## STEP 2 — Complexity Assessment

### Overall complexity: **8 / 10**

Not technically difficult per change, but **high-consequence and wide**: ~45 options, ~40 AJAX actions, 6 crons, 5 tables, 9 shortcodes, 2 external systems, plus a self-updater that gates its own continuity on the current names. The danger is concentrated in the persisted/contractual identifiers and the fact that two consumers (HA, WooCommerce orders) live outside the plugin's control.

### Top 3 Danger Zones

**🔴 #1 — Plugin identity & the GitHub auto-updater (folder, main file, slug, basename).**
Renaming the plugin directory or `mathlin-booking.php` changes the WordPress plugin basename. WP will deactivate the "old" plugin and see the renamed one as new; the updater (which hard-codes repo, subdir, slug, and `fix_source_dir` rename logic) will stop matching. Worst case: deactivation drops scheduled crons, the site shows duplicate plugins, and updates loop or fail. This is production-critical and the hardest to reverse cleanly.

**🔴 #2 — Database options, tables, and the custom capability (silent data loss / lockout).**
Renaming `mbs_*` option keys or `mathlin_*` tables without a migration makes the plugin read defaults instead of saved values — bank details, pricing tiers, access codes, T&Cs, email templates all appear "wiped." Renaming the `mbs_manage_bookings` capability instantly removes admin access for existing Booking Manager users. None of this errors loudly; it just quietly resets, which is the most dangerous failure mode for a live venue.

**🔴 #3 — External contracts: Home Assistant + WooCommerce in-flight orders.**
The REST namespace `mathlin/v1`, webhook id `mathlin_booking`, and event names are a contract with a separately-deployed HA box that the plugin can't update in the same release. Change one side and the building automation (heating/lighting/access) silently stops triggering. Likewise, renaming `_mbs_booking_ref` order meta breaks payment→booking reconciliation for any order placed before the cutover, so a hirer could pay and the booking never flips to "paid."

---

## STEP 3 — Phased Re-engineering Task List

Guiding principle: **decouple "what users see" from "what the system persists."** Do all cosmetic work first (shippable immediately, zero risk), then migrate data behind back-compat shims, and only rename external contracts last with dual-support windows.

### Phase 0 — Decision & Prep (before any code)
1. **Decide the rebrand depth.** Three viable scopes:
   - **(A) Cosmetic only** — change all human-visible text to "MGF Venue", leave every internal identifier (`mbs_`, `mathlin_`, slugs) untouched. ~1 day, near-zero risk. **Recommended unless there's a hard requirement to purge internal names.**
   - **(B) Cosmetic + new internal prefixes with migration** — full rename of options/crons/caps/meta with back-compat. Several days + careful testing.
   - **(C) Full rename including plugin slug/folder + HA domain + REST namespace.** Highest risk; only if the public-facing URLs/integration names must change.
2. **Stand up a staging clone** (DB + files). The repeated production update issues make blind cutover unacceptable for B/C. A DB snapshot immediately before cutover is mandatory.
3. **Inventory live pages** containing each `[mathlin_*]` shortcode and note their IDs.

### Phase 1 — Safe Renaming (text, UI, localized strings — no DB/contract changes)
*Shippable as one normal versioned release via the existing updater.*
1. Plugin header: `Plugin Name`, `Description` → "MGF Venue".
2. Admin UI strings: menu titles ("Scout Bookings" → "MGF Venue"), page `<h1>`s, button labels, descriptions.
3. Email-facing copy: headings, footers, `MBS_Email_Templates` default bodies/labels, `get_org_settings()` display name (already an option).
4. README.md / AI-CONTEXT.md / code comments / docblocks.
5. Updater "View details" display strings (`name`, author HTML) — display only, not the slug.
6. **Explicitly do NOT touch:** class names, constants, option keys, table names, cron hooks, AJAX actions, shortcodes, REST namespace, capability, order meta, plugin folder/file. Keep `MBS_`/`mbs_`/`mathlin_`/`nms-` as internal "legacy" identifiers.
7. Verify with `php -l`, ship, confirm the updater still recognises the plugin (proves identity unchanged).

> If scope = (A), you are done after Phase 1.

### Phase 2 — Database Migration Strategy (only for scope B/C)
Principle: **copy-then-verify, never rename-in-place; keep readers back-compatible during a transition window.**

1. **Introduce a compatibility layer first.** Add helper wrappers (e.g. `mgf_get_option()`) that read the new key and transparently fall back to the old key. Land this *before* any data moves so code never hard-depends on a half-finished migration.
2. **Options:** in a versioned `maybe_run_migrations()` step, for each `mbs_*` key: if new `mgf_*` key absent and old present, `add_option(new, get_option(old))`. **Do not delete** the old keys in the same release — leave them for one or two releases as rollback insurance.
3. **Tables:** prefer keeping table names (`mathlin_bookings`) — they're invisible to users and renaming buys nothing but risk. If mandated, create new tables, `INSERT ... SELECT` copy, run both behind a config flag, switch reads after row-count verification, drop old only after a soak period. Bump `mbs_db_version` to gate the migration so it runs once.
4. **User meta (`mbs_pricing_tier`, `mbs_scout_volunteer`):** loop users, copy to new keys, keep old as fallback for one release.
5. **Capability:** add the new cap to every role that currently has `mbs_manage_bookings` (don't remove the old cap yet); update `can_manage_bookings()` to accept either; remove the legacy cap only after confirming all managers retain access.
6. **Booking reference prefix `MBS-`:** **do not migrate existing refs** — they're printed on invoices, in hirers' inboxes, in WooCommerce orders, and used as primary lookup keys. At most, change the prefix for *new* bookings only, and make all lookups prefix-agnostic. Migrating historical refs is a data-integrity hazard with no upside.
7. **Idempotency + safety:** every migration guarded by an "already done" flag; take a DB backup in the activation/upgrade routine before mutating; log each step to the audit log.

### Phase 3 — Refactoring Hooks & Endpoints (most fragile; do last, with dual-support)
1. **Cron hooks:** when changing a cron name, on upgrade: schedule the new hook **and** `wp_clear_scheduled_hook()` the old one, so no job is orphaned and none double-runs. Verify via WP Crontrol / `wp cron event list` on staging.
2. **AJAX actions + nonces:** change PHP `add_action` names and the JS callers **in the same commit**; bump asset version (`MBS_VERSION`) to bust caches so no stale JS calls a removed action. Keep `enqueue_assets()`'s `strpos($hook,'mathlin')` guard aligned with any menu-slug change.
3. **Shortcodes:** register the new tags **and keep the old ones as aliases** (both pointing to the same handler) indefinitely, so existing published pages keep working. Update the `get_posts('s' => ...)` page-discovery strings to match. Migrate page content opportunistically, not as a hard cutover.
4. **REST namespace (`mathlin/v1`):** register the new namespace **in addition to** the old one (both routes live simultaneously). Only retire `mathlin/v1` after HA is confirmed migrated. Never flip it in a single release.
5. **Home Assistant (coordinated, out-of-band):**
   - Treat HA as a separate deployment with its own change window.
   - Keep the WP webhook id and event names stable until the HA component + `automations.yaml` + `configuration.yaml` are updated and tested together.
   - If the HA integration `DOMAIN` changes, plan entity-ID migration (occupancy/booking sensors) and a remove/re-add of the integration during a maintenance window when no booking is active.
6. **WooCommerce order meta:** make payment handlers read **both** `_mbs_booking_ref` and any new key for a long transition (orders are long-lived). Safest outcome: **leave the order meta keys as-is permanently** — they're invisible and back-compat cost is otherwise perpetual.
7. **Plugin slug / folder / GitHub repo (scope C only):** this is effectively a *migration to a new plugin identity*. Plan: ship a final `mathlin-booking` release that registers crons/options under new names (Phase 2/3), then a one-time bridge that activates the renamed plugin and deactivates the old basename, then update the updater's repo/subdir/slug/`fix_source_dir` to the new names. Requires a tested staging dry-run and a rollback snapshot. **Recommend deferring/avoiding unless the public plugin identity must change.**

### Cross-cutting verification (every phase)
- `php -l` on all touched files (no staging build step exists).
- On staging: confirm crons scheduled (none orphaned/duplicated), all shortcodes render, a test booking flows end-to-end (submit → confirm → HA webhook → pay → access email → feedback), and a Booking Manager (non-admin) still has access.
- Production cutover only after a DB snapshot; keep legacy identifiers for ≥1 release as rollback insurance.

---

## Recommendation

Adopt **Scope A (cosmetic) now** — it delivers the "MGF Venue" brand to every human-visible surface in a single low-risk release through the existing updater. Treat the internal `mbs_`/`mathlin_` identifiers as harmless legacy plumbing. Only escalate to Scope B/C if there is a concrete external requirement (e.g. the public REST URL or HA integration name must change), and only with a staging environment and DB snapshots in place — the auto-updater and the out-of-band HA/WooCommerce contracts make a deep rename disproportionately risky relative to its benefit.
