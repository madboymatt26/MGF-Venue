<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Included only after validating the existing invoice bearer token and payability.
?>
<style>
.mbs-pay-confirm{font-family:Arial,sans-serif;color:#222;line-height:1.6}
.mbs-pay-confirm h1{color:#7413dc;border:0;font-size:26px;margin-top:0}
.mbs-pay-summary{background:#f5f0ff;border-left:4px solid #7413dc;padding:16px;margin:20px 0}
.mbs-pay-summary strong{font-size:24px;display:block;color:#4c0b91}
.mbs-pay-confirm button{background:#7413dc;color:#fff;border:0;border-radius:6px;padding:14px 22px;font-size:16px;cursor:pointer}
.mbs-pay-confirm button:focus-visible{outline:3px solid #222;outline-offset:4px}
</style>
<div class="mbs-pay-confirm">
<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
<h1>Confirm invoice payment</h1>
<div class="mbs-pay-summary">
Invoice <?php echo esc_html( $invoice->invoice_ref ); ?>
<strong><?php echo esc_html( MBS_Money::format( $balance ) ); ?></strong>
Outstanding balance
</div>
<p>When you're ready, continue to secure checkout. Simply opening this page does not reserve the invoice or take payment.</p>
<form method="post" action="<?php echo esc_url( add_query_arg( 'mbs_invoice_pay', '1', wc_get_checkout_url() ) ); ?>">
<input type="hidden" name="invoice_ref" value="<?php echo esc_attr( $invoice->invoice_ref ); ?>">
<input type="hidden" name="invoice_token" value="<?php echo esc_attr( $token ); ?>">
<input type="hidden" name="mbs_invoice_confirm" value="1">
<?php wp_nonce_field( $action, '_mbs_invoice_nonce', false ); ?>
<button type="submit">Continue to secure checkout</button>
</form>
<p>No payment is taken until you complete the payment process at checkout.</p>
</div>
