# Booking-linked additional charges

Status: first implementation in the local working tree; not deployed. Release is gated on isolated WordPress/WooCommerce integration and browser checks.

## Purpose and boundaries

Allow staff to bill agreed extras such as cleaning or damage independently of the original hire. A charge may reference one booking or a series, including completed/archived bookings. It must never alter hire price, hire payment state, availability, access eligibility, recurrence or original invoice documents. No real customer charge is created by development or tests.

## Staff workflow

From a booking/series detail page, open Additional charges. Create a draft with category (cleaning, damage, other), a customer-visible description, exact GBP amount, service date and explicit due date. Copy the current hirer contact into the draft and confirm the recipient before issue. Internal notes are separate from customer-visible text. No CCTV or child information is copied into invoices or emails.

Drafts can be edited or discarded. Issue requires explicit confirmation and freezes the invoice snapshot. Sending the invoice is a separate explicit action, with a PDF and secure website payment route. Staff see draft, issued, partially paid, paid, overdue, credited and reconciliation-required states, plus related invoice/order references and an append-only audit trail. Reminders default off and are enabled explicitly per charge. Paid charges cannot be deleted; credit notes and cash refunds are distinct operations.

## Hirer workflow

The authenticated owner can see additional charges beside the linked hire, download PDFs and pay the exact remaining balance through existing WooCommerce checkout. Quick lookup must use the existing verified-owner/short-lived-token safeguards. Separate receipts describe the extra charge, not another hire payment. Payments must not trigger booking confirmations, access emails or Home Assistant events.

## Data and integration

The canonical entity is a standalone invoice with billing_mode=additional_charge and its own INV reference/version. Its single additional_charge item holds category, immutable booking/series association, internal notes and reminder preference in pricing_snapshot_json; dates, description, contact snapshot and money use existing ledger columns. No new schema or parallel payment ledger is needed. Reuse the immutable document renderer, delivery outbox and exact-value WooCommerce reservations. Never represent an extra charge as a hire allocation: item.booking_ref and invoice.series_ref stay null. Associations are informational, independent of hire allocations and monthly billing.

All amounts use validated integer minor units. Web mutations require booking-manager capability and CSRF nonce. Creation, credits and payment recording use stable idempotency keys; edits/issue/discard use canonical invoice version checks, so stale retries cannot mutate twice. Reusing a creation key with different input must fail. Issue, credit and settlement operations are transactional and fail closed. Outstanding checkout reservations block credit and offline settlement until resolved; charge reservation acquisition locks/refetches the invoice to serialize against credits. Webhook replay must never duplicate settlement or receipts.

## First-release scope and follow-ups

The manage API now also supports resend, send_credit and set_reminders. All
email operations require explicit confirm_send=true and stable request keys.
Credit email requires its document_ref; reminder updates require enabled=true/false.
List/detail responses expose payment-processing state and effective reminder
preference; detail also includes safe delivery-queue history. payment_review is
a list filter. These additions supersede the narrower operation list below.

Implemented: staff Additional charges screen and contextual booking/series links, paginated linked list, draft edit/discard, explicit immutable issue, explicit queued PDF/payment email, signed-in invoice/payment portal with ownership-controlled PDFs, independent settlement/refunds, opt-in single overdue reminder, offline settlement, credit PDF and cash-refund-review warning, existing accounting export with separate charge classification. Portal drafts/discarded invoices are hidden. Staff email sends use the organisation name; no personal author is invented. Existing payment-receipt templates are reused. Extra-charge transactions do not auto-post to OSM.

MCP parity: bridge 0.4.0 exposes list_additional_charges (related reference/status/pagination), get_additional_charge (safe invoice/items/association/internal notes/transactions/documents/audit/refund-review amount), manage_additional_charge (create/edit/issue/send/discard/credit), get_additional_charge_document (immutable invoice/credit HTML), and export_additional_charge_pdf (explicit local path, no overwrite). Existing record_invoice_payment handles offline settlement. Typed admin-only REST routes call the same charge services as the GUI. All writes require a stable request key; non-create writes also require the current invoice version. Send additionally requires confirm_send=true. Replay caching is bounded to 24 hours; callers must re-read current state after uncertain or expired retries. No payment links, bearer tokens or internal hashes are returned by ordinary reads. PDF export renders an already-issued document only, never issues or emails it. The candidate bridge must be installed/refreshed separately after the plugin is deployed; the running bridge/tool catalogue is not modified by this build.

Review-gap fixes: staff status/search filters, payment-processing state (including captured/unreconciled holds), payment/audit/email-queue history, editable draft category/organisation/address, explicit invoice resend with current balance, explicit credit-note email, configurable invoice/credit wording under Email Templates, and post-issue reminder preferences. Preference changes do not alter issued documents or reset the one-reminder cap. Payment requests and reminders are held for active/bound/reconciliation-required reservations. Signed-in hirers can download associated credit notes; verified reference+email Guest Quick Lookup shows separately labelled charges and credit PDFs without internal notes. Customer document references are kept in separate snapshot fields rather than appended to the 500-byte description. Email message keys are bounded hashes, safely within the queue's 100-character column.

Not included: a new automated cash-refund button or automatic OSM posting. Refunds use the existing payment-provider flow, not automatic cash return on credit. No production charge or customer email has been created. Full isolated WordPress/WooCommerce concurrency and browser acceptance checks remain a release gate; this machine has no installed Docker/Podman/WSL test runtime. Dependency-free tests and mocked transport checks are not substitutes for that gate.

Invoice totals and associations survive booking archive/cancellation and cannot be removed by ordinary booking/series deletion. Credits must not cause cancelled hire sessions to reappear or alter unrelated series invoices. Accounting exports identify additional charges separately; no new automatic OSM posting is enabled.

## Verification and release gates

The outbox also revalidates additional-charge payment requests immediately
before rendering/sending: paid invoices, held/reconciliation checkouts and
changed queued balances are not sent. A changed balance requires an explicit
fresh resend; retry/held failures remain visible in staff delivery history.

Local review verification: 32 dependency-free PHP programs (including validation,
API and behavioural delivery adapters), PHP syntax checks, JavaScript syntax,
admin-handler parity, 23-tool MCP schema and mocked PDF/dispatch checks. These
checks do not exercise a real WordPress database, WooCommerce gateway or browser.

Unit and real WordPress/WooCommerce database tests must cover permissions/ownership, malformed and fractional amounts, recipient validation, draft edits, immutable issuance/PDF, duplicate requests, concurrent issue/payment/credit, partial and full payment, duplicate callbacks, reservation expiry, delivery retry, offline settlement, credits and refund reconciliation. Assert original booking price, payment, status, access flags and allocations remain unchanged throughout. Cover archived one-offs, series links, cancelled hires, portal isolation, exports, reminder opt-in and zero-charge/negative-amount rejection.

Run the existing billing, financial/concurrency, invoice-document, portal and MCP regression suites. Production issuance, email delivery, publication and deployment require separate explicit authorization. An accepted GBP 25 cleaning charge may be used as a synthetic fixture, never with a real customer identity.
