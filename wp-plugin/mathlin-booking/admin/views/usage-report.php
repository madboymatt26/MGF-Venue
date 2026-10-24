<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once MBS_PLUGIN_DIR . 'includes/class-usage-report.php';
$from = sanitize_text_field( wp_unslash( $_GET['report_from'] ?? wp_date( 'Y-m-01' ) ) );
$to = sanitize_text_field( wp_unslash( $_GET['report_to'] ?? wp_date( 'Y-m-t' ) ) );
$error = '';
try { $usage = MBS_Usage_Report::report( $from, $to ); } catch ( Throwable $e ) { $error = $e->getMessage(); }
?>
<div class="wrap mbs-admin"><h1>Booking usage</h1>
<p>Payments and financial reporting are disabled. Booking usage remains available below; existing financial records have not been deleted.</p>
<form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( sanitize_key( $_GET['page'] ?? 'mathlin-analytics' ) ); ?>"><label>From <input type="date" name="report_from" value="<?php echo esc_attr($from); ?>" required></label> <label>To <input type="date" name="report_to" value="<?php echo esc_attr($to); ?>" required></label> <button class="button">Update period</button></form>
<?php if($error): ?><p role="alert"><?php echo esc_html($error); ?></p><?php else: ?>
<p>Approved booking-session occupancy only. Pending requests are excluded. Setup/clear-down buffers and blocked-date closures are not included in these metrics. Multiple areas and whole-park dependencies are expanded, so totals across spaces must not be added together as distinct bookings.</p>
<table class="widefat striped"><thead><tr><th>Space</th><th>Reservations</th><th>Occupied days</th><th>Occupied hours</th><th>Estimated attendees</th></tr></thead><tbody>
<?php foreach($usage['rows']as$row): ?><tr><td><?php echo esc_html($row['space']); ?></td><td><?php echo (int)$row['reservations']; ?></td><td><?php echo (int)$row['resource_days']; ?></td><td><?php echo esc_html($row['hours']); ?></td><td><?php echo (int)$row['attendees_estimate']; ?></td></tr><?php endforeach; ?>
</tbody></table><p class="description">Attendance is estimated once per reservation for this period, not measured attendance or camper nights. Hours use the reporting opening-hour settings.</p>
<?php if($usage['invalid_refs']): ?><p role="alert">Excluded invalid bookings: <?php echo esc_html(implode(', ',$usage['invalid_refs'])); ?></p><?php endif; endif; ?></div>
