<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div id="mbs-invoice-modal" class="mbs-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mbs-invoice-modal-title">
    <div class="mbs-modal__backdrop"></div>
    <div class="mbs-modal__content">
        <div class="mbs-modal__header">
            <h2 class="mbs-modal__title" id="mbs-invoice-modal-title">Invoice</h2>
            <button type="button" class="mbs-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="mbs-modal__body"><p class="mbs-modal__loading">Loading invoice…</p></div>
        <div class="mbs-modal__footer">
            <a href="#" class="button mbs-modal__download" target="_blank" rel="noopener">Download PDF</a>
            <button type="button" class="button mbs-modal__close-btn">Close</button>
        </div>
    </div>
</div>

