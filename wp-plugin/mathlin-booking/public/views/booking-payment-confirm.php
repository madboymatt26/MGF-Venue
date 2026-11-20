<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Included only after validating the booking bearer token and payability.
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
<h1>Confirm booking payment</h1>
<div class="mbs-pay-summary">
Booking <?php echo esc_html( $booking->ref ); ?>
<strong><?php echo esc_html( MBS_Money::format( $amount_minor ) ); ?></strong>
<?php echo esc_html( $payment_label ); ?>
</div>
<p>When you're ready, continue to secure checkout. Simply opening this page does not change your cart or take payment.</p>
<form method="post" action="<?php echo esc_url( add_query_arg( 'mbs_pay', '1', wc_get_checkout_url() ) ); ?>">
<input type="hidden" name="ref" value="<?php echo esc_attr( $booking->ref ); ?>">
<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
<input type="hidden" name="mbs_booking_confirm" value="1">
<?php wp_nonce_field( $action, '_mbs_booking_nonce', false ); ?>
<button type="submit">Continue to secure checkout</button>
</form>
<p>No payment is taken until you complete the payment process at checkout.</p>
</div>
