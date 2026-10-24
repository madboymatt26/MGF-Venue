# 3.41.0 — optional reusable extras

This is one delivery slice of the remaining roadmap, not the completion of the wider programme. Until a published tag, green hosted matrix and live checks are recorded, this document describes the build only.

## Staff workflow

On a site with payments, invoices and additional charges enabled, open MGF Venue → Additional charges. Administrators can expand **Reusable extras catalogue** to configure items and explicitly enable the optional catalogue. It starts disabled independently on each site.

Each item has a customer-visible description, unit, exact GBP unit price, category and optional reference account/tax codes. Codes are retained in invoice item snapshots for mapping review; this release does not automatically apply tax, change existing export layouts or post to any accounting API. OSM reconciliation is unchanged.

Open a booking → Additional charges → Add additional charge. Choose either an available catalogue item and whole quantity or the existing custom charge workflow. Check the linked booking, recipient, service date and due date, then save a draft. Catalogue prices and quantities are recalculated on the server. If the price or availability changed since the form loaded, reload and confirm the current item; no new charge is created from a stale selection.

Creating the draft does not issue or email an invoice. Issuance, sending, credit, cancellation and charge archiving remain their existing separate explicit actions. Editing a draft as a custom agreed total preserves the catalogue origin but resets the item quantity to one, preventing misleading unit arithmetic. Editing or archiving catalogue items never rewrites existing charges. Archive removes an item from new selections, not from financial history.

For free Stowmarket operation, the payment parent gate suppresses the catalogue and its writes regardless of a retained optional preference. No new customer checkout or payment prompt is introduced.

## MCP / REST

- GET `/mathlin/v1/admin/extras-catalogue`: administrator or booking manager; not public/read-only booking readers.
- POST to that route: administrator only; supported operations `enable`, `save`, `archive`, `restore`; supply current `revision`.
- Shared bridge 0.6.0 advertises 24 tools, including `manage_extras_catalogue`; read via `get_admin_resource` with `extras_catalogue`.
- Creating a charge supports `catalogue_id`, `catalogue_version`, `catalogue_quantity`. These are create-only selectors, not caller-supplied price snapshots. Existing stable charge request keys remain required.

The configuration uses byte-exact compare-and-swap so stale tabs cannot overwrite concurrent catalogue edits. New saves include revision, actor and timestamp and write the normal staff audit log. No database schema migration or booking-label migration is included.

## Other correctness fix

Exact-money parsing now rejects integer overflow instead of silently clamping oversized input or converting multiplication to floating point. Minimum-integer formatting is string-based and does not overflow through `abs()`. Ordinary prices, fractional quantities, rounding and existing payment deadlines are unchanged.

## Evidence / release gates

Local isolated PHP suite: 73 programs passed. Extras tests: 47 validation/state checks and 15 picker-state checks. Real disposable WordPress/MariaDB test: 18 assertions for CAS, server-authoritative line quantity/price, draft-only creation, stable retries, preserved snapshots and payment-off gating. Hosted matrix, release package, live UI and configuration retention are separate outstanding deployment gates until recorded.

See `remaining-roadmap.md` for the other programme slices and external dependencies.
