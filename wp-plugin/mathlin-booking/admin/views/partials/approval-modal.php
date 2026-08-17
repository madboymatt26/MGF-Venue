<?php
/**
 * Shared Review & Approve modal.
 *
 * Rendered on list, single, and series pages. Populated dynamically via JS
 * when opened (AJAX fetches the series billing defaults for the given ref).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="mbs-approval-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);overflow:auto;">
    <div style="max-width:700px;margin:60px auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h2 style="color:#7413DC;margin-top:0;">Review &amp; Approve Series</h2>
        <p>Confirm billing configuration before approving <strong id="mbs-approval-series-ref"></strong>.</p>
        <table class="form-table" style="margin:16px 0;">
            <tr><th>Space</th><td id="mbs-approval-space">—</td></tr>
            <tr><th>Price per occurrence</th><td id="mbs-approval-price">—</td></tr>
            <tr><th>Estimated total</th><td id="mbs-approval-total">—</td></tr>
            <tr><th>Accepted dates</th><td id="mbs-approval-dates">—</td></tr>
        </table>
        <form id="mbs-approval-form">
            <input type="hidden" name="series_ref" value="">
            <input type="hidden" name="expected_version" value="">
            <table class="form-table"><tbody>
                <tr><th><label>Billing frequency</label></th><td><select name="billing_mode">
                    <option value="monthly">Monthly in advance</option>
                    <option value="termly">Termly</option>
                    <option value="upfront">Whole series upfront</option>
                    <option value="none">No charge</option>
                </select></td></tr>
                <tr><th><label>Billing treatment</label></th><td><select name="billing_treatment">
                    <option value="invoice_managed">Generate consolidated invoices automatically</option>
                    <option value="manual_consolidated">Manage billing manually</option>
                    <option value="none">No billing</option>
                </select></td></tr>
                <tr><th><label>Payment method</label></th><td><select name="payment_method">
                    <option value="online">Online card payment</option>
                    <option value="offline_bacs">BACS / Purchase Order</option>
                    <option value="none">No payment</option>
                </select></td></tr>
                <tr><th>Invoice lead time</th><td><input type="number" name="invoice_lead_days" min="0" max="365" value="28"> days</td></tr>
                <tr><th>Payment terms</th><td><input type="number" name="payment_terms_days" min="0" max="365" value="14"> days</td></tr>
                <tr class="mbs-term-dates-row" style="display:none;"><th>Term dates</th><td>
                    <div id="mbs-term-editor"><p class="description">Add named term periods.</p><div id="mbs-term-list"></div><button type="button" class="button" id="mbs-add-term">+ Add term</button></div>
                </td></tr>
            </tbody></table>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px;">
                <button type="button" class="button" id="mbs-cancel-approval">Cancel</button>
                <button type="submit" class="button button-primary">Confirm &amp; Approve</button>
            </div>
            <p class="mbs-approval-message" style="margin-top:12px;"></p>
        </form>
    </div>
</div>
