<?php
global $wpdb;$mode=isset($args[0])?(string)$args[0]:'disable';$invoice_ref=isset($args[1])?sanitize_text_field($args[1]):'';$table=$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE;
if($mode==='disable'){
    $rows=$wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='invoice_owner'");if($rows&&$wpdb->query("ALTER TABLE {$table} DROP INDEX invoice_owner")===false)throw new RuntimeException($wpdb->last_error);echo "guard_disabled\n";return;
}
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE invoice_ref=%s",$invoice_ref));$rows=$wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='invoice_owner'");if(!$rows&&$wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY invoice_owner (invoice_id)")===false)throw new RuntimeException($wpdb->last_error);echo "guard_restored\n";
