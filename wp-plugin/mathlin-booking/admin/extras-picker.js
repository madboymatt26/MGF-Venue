/* Configuration is a convenience only; charge creation rechecks version and price on the server. */
(() => {
    'use strict';
    const picker = document.getElementById('mbs-extra-picker');
    if (!picker || typeof MBS_Extras === 'undefined') return;
    const form = picker.closest('form');
    const quantity = form.elements.catalogue_quantity;
    const version = form.elements.catalogue_version;
    const amount = form.elements.amount;
    const description = form.elements.description;
    const category = form.elements.category;
    const status = document.getElementById('mbs-extra-total');
    let custom = null;
    function refresh() {
        const item = MBS_Extras.items.find(i => i.id === picker.value);
        if (!item) {
            if (custom) { amount.value = custom.amount; description.value = custom.description; category.value = custom.category; }
            custom = null; version.value = ''; quantity.disabled = true; quantity.setCustomValidity('');
            amount.readOnly = false; description.readOnly = false; category.disabled = false;
            status.textContent = 'Custom charges use the description and amount below.';
            return;
        }
        if (!custom) custom = {amount: amount.value, description: description.value, category: category.value};
        version.value = String(item.version); quantity.disabled = false;
        amount.readOnly = true; description.readOnly = true; category.disabled = true;
        const count = Number(quantity.value);
        if (!Number.isInteger(count) || count < 1 || count > 9999 || count * item.unit_amount_minor > 100000000) {
            quantity.setCustomValidity('Enter a whole quantity from 1 to 9999 with a total no greater than £1,000,000.');
            status.textContent = 'Check the quantity before saving.';
            return;
        }
        quantity.setCustomValidity('');
        const minor = count * item.unit_amount_minor;
        amount.value = `${Math.floor(minor / 100)}.${String(minor % 100).padStart(2, '0')}`;
        description.value = `${item.description} (${count} × ${item.unit})`;
        category.value = item.category;
        status.textContent = `Draft total: £${amount.value}. No invoice is issued or email sent until you confirm separately.`;
    }
    picker.addEventListener('change', refresh);
    quantity.addEventListener('input', refresh);
    refresh();
})();
