<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared HTML Invoice Renderer.
 *
 * Produces standalone HTML from an MBS_Invoice_Document_View_Model.
 * Used for:
 *   - Admin inline invoice view (iframe/modal)
 *   - Hirer portal inline view
 *   - Input to the PDF renderer (Dompdf consumes the same HTML)
 *
 * The Issued mode renders ONLY from the immutable snapshot.
 * The Current Account mode adds clearly labelled live payment state.
 *
 * Error contract: returns string (HTML) or WP_Error.
 */
class MBS_HTML_Renderer {

    /**
     * Render an invoice document as standalone HTML.
     *
     * @param MBS_Invoice_Document_View_Model $model
     * @return string|WP_Error  Complete HTML document string.
     */
    public static function render( $model ) {
        if ( ! $model || ! $model->snapshot ) {
            return new WP_Error( 'render_no_model', 'No invoice document model provided for rendering.' );
        }

        $s = $model->snapshot;
        $accent = esc_attr( $model->accent_colour );

        // Resolve logo
        $logo_html = '';
        if ( $s->logo_asset_id && $s->logo_content_hash ) {
            $data_uri = MBS_Logo_Asset::get_data_uri( $s->logo_asset_id, $s->logo_content_hash );
            if ( ! is_wp_error( $data_uri ) ) {
                $logo_html = '<img src="' . esc_attr( $data_uri ) . '" alt="' . esc_attr( $s->issuer_name ) . '" style="max-height:60px;max-width:200px;height:auto;margin-bottom:8px;">';
            }
        }

        ob_start();
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $s->document_type === 'credit_note' ? 'Credit Note' : 'Invoice' ); ?> <?php echo esc_html( $s->invoice_number ); ?></title>
<style>
body{font-family:Arial,sans-serif;color:#1a1a2e;max-width:800px;margin:0 auto;padding:40px 20px;font-size:14px;line-height:1.5;}
.inv-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:32px;border-bottom:3px solid <?php echo $accent; ?>;padding-bottom:24px;}
.inv-org h2{color:<?php echo $accent; ?>;margin:0 0 8px;font-size:18px;}
.inv-org p{margin:0;color:#666;font-size:13px;line-height:1.6;}
.inv-meta{text-align:right;}
.inv-number{font-size:20px;font-weight:bold;color:<?php echo $accent; ?>;margin-bottom:8px;}
.inv-meta p{margin:4px 0;font-size:13px;}
.inv-parties{display:flex;gap:40px;margin-bottom:32px;}
.inv-party{flex:1;}
.inv-party h4{color:<?php echo $accent; ?>;margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:1px;}
.inv-party p{margin:0;line-height:1.6;}
table.inv-items{width:100%;border-collapse:collapse;margin-bottom:24px;}
table.inv-items thead th{background:<?php echo $accent; ?>;color:#fff;padding:10px 12px;text-align:left;font-size:12px;}
table.inv-items thead th.r{text-align:right;}
table.inv-items tbody td{padding:10px 12px;border-bottom:1px solid #e0d0f0;vertical-align:top;}
table.inv-items tbody td.r{text-align:right;white-space:nowrap;}
table.inv-items tbody td small{color:#666;display:block;margin-top:2px;}
.inv-totals{margin-left:auto;width:300px;}
.inv-total-row{display:flex;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #eee;}
.inv-total-row.grand{background:#f5f0ff;font-weight:bold;font-size:16px;border:2px solid <?php echo $accent; ?>;border-radius:4px;margin-top:8px;}
.inv-notes{margin-top:32px;padding:20px;background:#f9f7ff;border-radius:8px;border:1px solid #e0d0f0;}
.inv-notes h5{margin:0 0 8px;color:<?php echo $accent; ?>;font-size:13px;}
.inv-notes p{margin:0 0 12px;line-height:1.8;}
.inv-status{display:inline-block;padding:4px 12px;border-radius:4px;font-weight:bold;font-size:12px;text-transform:uppercase;}
.inv-status-paid{background:#d1fae5;color:#065f46;}
.inv-status-overdue{background:#fee2e2;color:#991b1b;}
.inv-status-voided{background:#e5e7eb;color:#4b5563;text-decoration:line-through;}
.inv-legacy-banner{background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:24px;font-size:13px;color:#92400e;}
.inv-account-section{margin-top:24px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;}
.inv-account-section h5{margin:0 0 8px;color:#0369a1;font-size:13px;}
.pay-btn{display:inline-block;background:#2ecc71;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px;margin-top:12px;}
@media print{body{padding:0;}.pay-btn{display:none;}}
</style>
</head>
<body>
<?php if ( $model->is_legacy_reconstruction ) : ?>
<div class="inv-legacy-banner">&#9888; Reconstructed copy — original issuer snapshot unavailable. Issuer details shown are current, not historical.</div>
<?php endif; ?>

<?php if ( $s->document_type === 'credit_note' ) : ?>
<div style="text-align:center;margin-bottom:24px;"><span class="inv-status" style="background:#e0d0f0;color:<?php echo $accent; ?>;font-size:16px;padding:8px 20px;">CREDIT NOTE</span></div>
<?php endif; ?>

<?php if ( $s->status_at_issue === 'void' ) : ?>
<div style="text-align:center;margin-bottom:24px;"><span class="inv-status inv-status-voided" style="font-size:16px;padding:8px 20px;">VOIDED</span></div>
<?php endif; ?>

<div class="inv-header">
<div class="inv-org">
<?php echo $logo_html; ?>
<h2><?php echo esc_html( $s->issuer_name ); ?></h2>
<p><?php echo esc_html( $s->issuer_address ); ?><br>
<?php echo esc_html( $s->issuer_email ); ?><?php if ( $s->issuer_phone ) : ?> &bull; <?php echo esc_html( $s->issuer_phone ); ?><?php endif; ?>
<?php if ( $s->issuer_charity_number ) : ?><br>Registered Charity No. <?php echo esc_html( $s->issuer_charity_number ); ?><?php endif; ?></p>
</div>
<div class="inv-meta">
<div class="inv-number"><?php echo esc_html( $s->invoice_number ); ?></div>
<p>Issue Date: <?php echo esc_html( self::format_date( $s->issue_date ) ); ?></p>
<?php if ( $s->due_date ) : ?><p>Due Date: <strong><?php echo esc_html( self::format_date( $s->due_date ) ); ?></strong></p><?php endif; ?>
<?php if ( $s->period_start && $s->period_end ) : ?><p>Period: <?php echo esc_html( self::format_date( $s->period_start ) . ' – ' . self::format_date( $s->period_end ) ); ?></p><?php endif; ?>
<?php if ( $s->booking_ref ) : ?><p>Booking: <?php echo esc_html( $s->booking_ref ); ?></p><?php endif; ?>
<?php if ( $s->series_ref ) : ?><p>Series: <?php echo esc_html( $s->series_ref ); ?></p><?php endif; ?>
</div>
</div>

<div class="inv-parties">
<div class="inv-party">
<h4>From</h4>
<p><strong><?php echo esc_html( $s->issuer_name ); ?></strong><br><?php echo nl2br( esc_html( $s->issuer_address ) ); ?></p>
</div>
<div class="inv-party">
<h4>Bill To</h4>
<p><strong><?php echo esc_html( $s->recipient_name ); ?></strong>
<?php if ( $s->recipient_organisation ) : ?><br><?php echo esc_html( $s->recipient_organisation ); ?><?php endif; ?>
<?php if ( $s->recipient_address ) : ?><br><?php echo nl2br( esc_html( $s->recipient_address ) ); ?><?php endif; ?>
<br><?php echo esc_html( $s->recipient_email ); ?></p>
</div>
</div>

<table class="inv-items">
<thead><tr><th>Date</th><th>Description</th><th class="r">Amount</th></tr></thead>
<tbody>
<?php foreach ( $s->line_items as $item ) : ?>
<tr>
<td><?php echo esc_html( self::format_date( $item['date'] ?? '' ) ); ?></td>
<td><?php echo esc_html( $item['description'] ?? '' ); ?><?php if ( ! empty( $item['space'] ) ) : ?><small><?php echo esc_html( $item['space'] ); ?></small><?php endif; ?></td>
<td class="r"><?php echo esc_html( self::format_money( $item['amount_minor'] ?? 0, $s->currency ) ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="inv-totals">
<div class="inv-total-row"><span>Subtotal</span><span><?php echo esc_html( self::format_money( $s->subtotal_minor, $s->currency ) ); ?></span></div>
<?php if ( $s->tax_label ) : ?>
<div class="inv-total-row"><span><?php echo esc_html( $s->tax_label ); ?><?php if ( $s->tax_rate_bps > 0 ) echo ' (' . number_format( $s->tax_rate_bps / 100, 2 ) . '%)'; ?></span><span><?php echo esc_html( self::format_money( $s->tax_amount_minor, $s->currency ) ); ?></span></div>
<?php endif; ?>
<?php if ( $s->credits_minor > 0 ) : ?>
<div class="inv-total-row"><span>Credits applied</span><span style="color:#991b1b;">-<?php echo esc_html( self::format_money( $s->credits_minor, $s->currency ) ); ?></span></div>
<?php endif; ?>
<div class="inv-total-row grand"><span>Total</span><span><?php echo esc_html( self::format_money( $s->total_minor, $s->currency ) ); ?></span></div>
</div>

<?php // Payment instructions (from immutable snapshot — no live URLs) ?>
<?php if ( $s->status_at_issue !== 'void' && $s->total_minor > 0 ) : ?>
<div class="inv-notes">
<?php if ( $s->payment_schedule ) : ?>
<h5>Payment Schedule</h5>
<?php echo self::render_payment_schedule( $s ); ?>
<?php else : ?>
<h5>Payment Terms</h5>
<p>Payment due within <strong><?php echo (int) $s->payment_terms_days; ?> days</strong> of issue.</p>
<?php endif; ?>

<h5>Payment Method</h5>
<?php if ( $s->payment_method === 'offline_bacs' && $s->bank_details ) : ?>
<p>Please quote reference <strong><?php echo esc_html( $s->invoice_number ); ?></strong> with all payments.<br>
Account name: <strong><?php echo esc_html( $s->bank_details['account_name'] ?? '' ); ?></strong><br>
Sort code: <strong><?php echo esc_html( $s->bank_details['sort_code'] ?? '' ); ?></strong><br>
Account number: <strong><?php echo esc_html( $s->bank_details['account_number'] ?? '' ); ?></strong></p>
<?php elseif ( $s->payment_method === 'online' ) : ?>
<p><?php echo esc_html( $s->online_payment_instruction ); ?></p>
<?php endif; ?>
</div>
<?php endif; ?>

<?php // Current Account section (only in current_account mode) ?>
<?php if ( $model->mode === 'current_account' && $model->account_state ) : $a = $model->account_state; ?>
<div class="inv-account-section">
<h5>Payment Status (as of <?php echo esc_html( wp_date( 'j F Y' ) ); ?>)</h5>
<div class="inv-totals" style="width:100%;margin:0;">
<div class="inv-total-row"><span>Invoice total</span><span><?php echo esc_html( self::format_money( $s->total_minor, $s->currency ) ); ?></span></div>
<?php if ( $a->payments_received_minor > 0 ) : ?>
<div class="inv-total-row"><span>Payments received</span><span style="color:#065f46;">-<?php echo esc_html( self::format_money( $a->payments_received_minor, $s->currency ) ); ?></span></div>
<?php endif; ?>
<?php if ( $a->credits_applied_minor > 0 ) : ?>
<div class="inv-total-row"><span>Credits applied</span><span style="color:#065f46;">-<?php echo esc_html( self::format_money( $a->credits_applied_minor, $s->currency ) ); ?></span></div>
<?php endif; ?>
<?php if ( $a->refunded_minor > 0 ) : ?>
<div class="inv-total-row"><span>Refunded</span><span style="color:#991b1b;"><?php echo esc_html( self::format_money( $a->refunded_minor, $s->currency ) ); ?></span></div>
<?php endif; ?>
<div class="inv-total-row grand"><span>Balance outstanding</span><span><?php echo esc_html( self::format_money( $a->outstanding_balance_minor, $s->currency ) ); ?></span></div>
</div>
<?php if ( $a->outstanding_balance_minor <= 0 ) : ?>
<p><span class="inv-status inv-status-paid">PAID</span></p>
<?php elseif ( $a->is_overdue ) : ?>
<p><span class="inv-status inv-status-overdue">OVERDUE</span></p>
<?php endif; ?>
<?php if ( $a->pay_now_url && $a->outstanding_balance_minor > 0 ) : ?>
<a href="<?php echo esc_url( $a->pay_now_url ); ?>" class="pay-btn">Pay Now <?php echo esc_html( self::format_money( $a->outstanding_balance_minor, $s->currency ) ); ?></a>
<?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
<?php
        return ob_get_clean();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function format_date( $date ) {
        if ( ! $date ) return '';
        $ts = strtotime( $date );
        return $ts ? wp_date( 'j F Y', $ts ) : $date;
    }

    private static function format_money( $minor, $currency = 'GBP' ) {
        if ( class_exists( 'MBS_Money' ) ) {
            $formatted = MBS_Money::format( (int) $minor, $currency );
            return is_wp_error( $formatted ) ? '£0.00' : $formatted;
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = abs( (int) $minor );
        $symbol = strtoupper( $currency ) === 'GBP' ? '£' : strtoupper( $currency ) . ' ';
        return $sign . $symbol . intdiv( $abs, 100 ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
    }

    private static function render_payment_schedule( $s ) {
        $ps = $s->payment_schedule;
        if ( ! is_array( $ps ) ) return '';
        $html = '<p>';
        if ( ! empty( $ps['no_charge'] ) ) {
            $html .= 'No charge applies.';
        } elseif ( ! empty( $ps['immediate'] ) ) {
            $html .= 'Full payment of <strong>' . esc_html( self::format_money( $s->total_minor, $s->currency ) ) . '</strong> due immediately.';
        } elseif ( ! empty( $ps['deposit_minor'] ) ) {
            $html .= 'Deposit due: <strong>' . esc_html( self::format_money( $ps['deposit_minor'], $s->currency ) ) . '</strong>';
            if ( ! empty( $ps['deposit_due_date'] ) ) $html .= ' by ' . esc_html( self::format_date( $ps['deposit_due_date'] ) );
            $html .= '<br>';
            if ( ! empty( $ps['balance_minor'] ) ) {
                $html .= 'Balance: <strong>' . esc_html( self::format_money( $ps['balance_minor'], $s->currency ) ) . '</strong>';
                if ( ! empty( $ps['balance_due_date'] ) ) $html .= ' by ' . esc_html( self::format_date( $ps['balance_due_date'] ) );
            }
        } else {
            $html .= 'Payment due within <strong>' . (int) $s->payment_terms_days . ' days</strong>.';
        }
        $html .= '</p>';
        return $html;
    }
}
