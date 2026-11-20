<?php
/**
 * Shared Review & Approve modal.
 *
 * Rendered on list, single, and series pages. Populated dynamically via JS
 * when opened (AJAX fetches the series billing defaults for the given ref).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="mbs-approval-modal" class="mbs-modal" role="dialog" aria-modal="true" aria-labelledby="mbs-approval-modal-title" hidden style="display:none;">
    <div class="mbs-modal__backdrop mbs-approval-modal__backdrop"></div>
    <div class="mbs-modal__content mbs-approval-modal__content">
        <div class="mbs-modal__header">
            <h2 id="mbs-approval-modal-title" class="mbs-modal__title">Review &amp; Approve Series</h2>
            <button type="button" class="mbs-modal__close mbs-approval-modal__close" aria-label="Close approval modal">&times;</button>
        </div>
        <div class="mbs-modal__body">
            <p>Confirm billing configuration before approving <strong id="mbs-approval-series-ref"></strong>.</p>
            <table class="form-table mbs-approval-summary-table">
                <tr><th scope="row">Space</th><td id="mbs-approval-space">—</td></tr>
                <tr><th scope="row">Price per occurrence</th><td id="mbs-approval-price">—</td></tr>
                <tr><th scope="row">Estimated total</th><td id="mbs-approval-total">—</td></tr>
                <tr><th scope="row">Accepted dates</th><td id="mbs-approval-dates">—</td></tr>
            </table>
            <form id="mbs-approval-form">
                <input type="hidden" name="series_ref" value="">
                <input type="hidden" name="expected_version" value="">
                <table class="form-table"><tbody>
                    <tr><th scope="row"><label for="mbs-approval-billing-mode">Billing frequency</label></th><td><select id="mbs-approval-billing-mode" name="billing_mode">
                        <option value="monthly">Monthly in advance</option>
                        <option value="termly">Termly</option>
                        <option value="upfront">Whole series upfront</option>
                        <option value="none">No charge</option>
                    </select></td></tr>
                    <tr><th scope="row"><label for="mbs-approval-billing-treatment">Billing treatment</label></th><td><select id="mbs-approval-billing-treatment" name="billing_treatment">
                        <option value="invoice_managed">Generate consolidated invoices automatically</option>
                        <option value="manual_consolidated">Manage billing manually</option>
                        <option value="none">No billing</option>
                    </select></td></tr>
                    <tr><th scope="row"><label for="mbs-approval-payment-method">Payment method</label></th><td><select id="mbs-approval-payment-method" name="payment_method">
                        <option value="online">Online card payment</option>
                        <option value="offline_bacs">BACS / Purchase Order</option>
                        <option value="none">No payment</option>
                    </select></td></tr>
                    <tr><th scope="row"><label for="mbs-approval-lead-days">Invoice lead time</label></th><td><input id="mbs-approval-lead-days" type="number" name="invoice_lead_days" min="0" max="365" value="28"> days</td></tr>
                    <tr><th scope="row"><label for="mbs-approval-terms-days">Payment terms</label></th><td><input id="mbs-approval-terms-days" type="number" name="payment_terms_days" min="0" max="365" value="14"> days</td></tr>
                    <tr class="mbs-term-dates-row" style="display:none;"><th scope="row">Term dates</th><td>
                        <div id="mbs-term-editor"><p class="description">Add named term periods.</p><div id="mbs-term-list"></div><button type="button" class="button" id="mbs-add-term">+ Add term</button></div>
                    </td></tr>
                </tbody></table>
                <div class="mbs-approval-modal__actions">
                    <button type="button" class="button" id="mbs-cancel-approval">Cancel</button>
                    <button type="submit" class="button button-primary">Confirm &amp; Approve</button>
                </div>
                <p class="mbs-approval-message" role="status" aria-live="polite"></p>
            </form>
        </div>
    </div>
</div>
