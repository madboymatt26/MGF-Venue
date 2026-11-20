<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div id="mbs-email-diagnostics-modal" class="mbs-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mbs-email-modal-title">
    <div class="mbs-modal__backdrop mbs-email-modal__backdrop"></div>
    <div class="mbs-modal__content mbs-email-modal__content">
        <div class="mbs-modal__header">
            <h2 id="mbs-email-modal-title" class="mbs-modal__title">Email Delivery Diagnostics</h2>
            <button type="button" class="mbs-modal__close mbs-email-modal__close" aria-label="Close email diagnostics">&times;</button>
        </div>
        <div class="mbs-email-modal__toolbar">
            <div class="mbs-email-modal__tabs" role="tablist" aria-label="Email queue filters">
                <button type="button" class="mbs-email-tab is-active" role="tab" aria-selected="true" data-tab="transport">
                    Transport Failures <span class="mbs-email-tab__count" data-count-key="transport_failed">0</span>
                </button>
                <button type="button" class="mbs-email-tab" role="tab" aria-selected="false" data-tab="obsolete">
                    Stale / Ineligible <span class="mbs-email-tab__count" data-count-key="obsolete_failed">0</span>
                </button>
                <button type="button" class="mbs-email-tab" role="tab" aria-selected="false" data-tab="pending">
                    Pending / Retrying <span class="mbs-email-tab__count" data-count-key="pending">0</span>
                </button>
                <button type="button" class="mbs-email-tab" role="tab" aria-selected="false" data-tab="sent">
                    Recently Sent <span class="mbs-email-tab__count" data-count-key="sent">0</span>
                </button>
            </div>
            <div class="mbs-email-modal__context" id="mbs-email-modal-context" style="display:none;">
                <span class="mbs-email-modal__context-label"></span>
                <button type="button" class="button button-small mbs-email-modal__clear-context">Show all emails</button>
            </div>
        </div>
        <div class="mbs-modal__body mbs-email-modal__body" id="mbs-email-modal-body" aria-live="polite">
            <p class="mbs-modal__loading">Loading email queue diagnostics&hellip;</p>
        </div>
        <div class="mbs-modal__footer">
            <span class="mbs-email-modal__status" id="mbs-email-modal-status" role="status" aria-live="polite"></span>
            <button type="button" class="button mbs-email-modal__refresh">Refresh</button>
            <button type="button" class="button button-primary mbs-email-modal__close">Close</button>
        </div>
    </div>
</div>
