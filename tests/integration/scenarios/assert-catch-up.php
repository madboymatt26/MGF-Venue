<?php
global $wpdb;
$invoice_table=$wpdb->prefix.MBS_INVOICE_TABLE;
$item_table=$wpdb->prefix.MBS_INVOICE_ITEM_TABLE;
$allocation_table=$wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE;
$duplicates=$wpdb->get_results("SELECT series_ref,COUNT(*) AS invoices FROM {$invoice_table} WHERE series_ref LIKE 'INT-CU-%' GROUP BY series_ref HAVING COUNT(*)<>1");
$invoice_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$invoice_table} WHERE series_ref LIKE 'INT-CU-%'");
$item_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$item_table} i INNER JOIN {$invoice_table} v ON v.id=i.invoice_id WHERE v.series_ref LIKE 'INT-CU-%'");
$allocation_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$allocation_table} a INNER JOIN {$invoice_table} v ON v.id=a.invoice_id WHERE v.series_ref LIKE 'INT-CU-%' AND a.status='active'");
$bad_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$invoice_table} WHERE series_ref='INT-CU-050'");
if($duplicates||$invoice_count!==106||$item_count!==106||$allocation_count!==106||$bad_count!==0)throw new RuntimeException('Overlapping catch-up workers skipped, duplicated, or invoiced the invalid series.');
echo "OK: overlapping catch-up workers produced 106 unique invoices/items/allocations with no skips, duplicates, or starvation.\n";
