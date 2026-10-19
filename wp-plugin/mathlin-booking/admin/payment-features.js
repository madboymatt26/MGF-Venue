/* Presentation only. All financial mutations are also checked on the server. */
jQuery(function ($) {
    'use strict';
    var flags = MBS_Admin.payment_features || {};
    function on(feature) { return !flags[feature] || flags[feature].enabled; }
    function hide(selector) { $(selector).prop('hidden', true).hide(); }
    if (!on('manual')) {
        hide('.nms-btn-paid,.nms-btn-unpaid,.nms-btn-deposit-paid,.nms-btn-undo-deposit,.nms-btn-mark-balance-paid,.nms-btn-mark-refunded,.mbs-record-payment-toggle,.mbs-payment-panel');
        $('input[name="payment_amount"]').closest('form').prop('hidden',true).hide();
    }
    if (!on('reminders')) {
        hide('.nms-btn-chase');
        $('#max_chase_emails,#chase_interval_days,#cron_time_chase,#auto_chase_enabled').closest('tr').prop('hidden',true).hide();
        $('button[name="operation"][value="set_reminders"]').closest('form').prop('hidden',true).hide();
        $('input[name="automatic_reminders"]').closest('label').prop('hidden',true).hide();
    }
    if (!on('invoices')) {
        hide('.nms-series-catch-up,.nms-btn-series-pause');
        $('#bank_sort_code,#bank_account_number,#bank_account_name').closest('.nms-card').prop('hidden',true).hide();
    }
    if (!on('payments')) {
        $('#payment_days_required,#offline_payment_instructions,#payment_terms_days,#kitchen_price').closest('tr').prop('hidden',true).hide();
        $('.nms-detail-item').filter(function () { return /^(Amount|Invoice No\.|Billing Address)$/.test($(this).find('label').first().text().trim()); }).prop('hidden',true).hide();
        $('#nms-edit-custom-amount,#nms-edit-use-custom,#nms-edit-address').closest('.nms-form-group,tr').prop('hidden',true).hide();
        $('.nms-spaces-table tr').each(function () { $(this).children().eq(1).prop('hidden',true).hide(); $(this).children().eq(2).prop('hidden',true).hide(); });
    }
    if (!on('reports')) {
        $('.nms-card').filter(function () {
            return /Revenue|Financial Measures|Top Hirers by Booked Value|Accounting Export|Payment Routes|What was added to OSM|Recent additions to OSM|OSM|Cash|Outstanding|Reconciliation/.test($(this).find('.nms-card-header h2').first().text());
        }).prop('hidden',true).hide();
        $('.nms-stat-revenue,.mbs-dw-stat').filter(function () {return /Revenue|Paid|Outstanding|Overdue/.test($(this).text());}).prop('hidden',true).hide();
    }
});
