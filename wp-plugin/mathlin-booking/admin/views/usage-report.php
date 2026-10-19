<?php
if ( ! defined( 'ABSPATH' ) ) exit;
global $wpdb;
$usage_rows = $wpdb->get_results( "SELECT space,COUNT(*) AS bookings,SUM(attendees) AS attendees FROM {$wpdb->prefix}" . MBS_TABLE . " WHERE status IN ('pending','confirmed','deposit_paid','paid') GROUP BY space ORDER BY space" );
?>
<div class="wrap mbs-admin"><h1>Booking usage</h1>
<p>Payments and financial reporting are disabled. Booking usage remains available below; existing financial records have not been deleted.</p>
<table class="widefat striped"><thead><tr><th>Space</th><th>Active booked dates</th><th>Booked attendees</th></tr></thead><tbody>
<?php foreach ( $usage_rows as $usage ) : ?><tr><td><?php echo esc_html( $usage->space ); ?></td><td><?php echo (int) $usage->bookings; ?></td><td><?php echo (int) $usage->attendees; ?></td></tr><?php endforeach; ?>
</tbody></table><p class="description">All recorded active dates, including pending requests. Attendee totals are booking estimates, not measured attendance.</p></div>
