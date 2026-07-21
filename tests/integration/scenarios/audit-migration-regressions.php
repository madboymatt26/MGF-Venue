<?php
global $wpdb;

$mbs_audit_migration_failures=array();
function mbs_audit_migration_assert($condition,$message){
    global $mbs_audit_migration_failures;
    if(!$condition){$mbs_audit_migration_failures[]=$message;fwrite(STDERR,"AUDIT MIGRATION REGRESSION: {$message}\n");}
}

$booking_table=$wpdb->prefix.MBS_TABLE;
$series_table=$wpdb->prefix.MBS_SERIES_TABLE;
$reservation_table=$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE;
$date=wp_date('Y-m-d',strtotime('+120 days'));
$now=current_time('mysql');

// Reproduce an already-registered schema-6 legacy series before schema 7 adds
// legacy_billing_excluded. Registration must not be required for the backfill.
$wpdb->insert($series_table,array(
    'series_ref'=>'INT-A-LEGACY-UPGRADE','status'=>'confirmed','version'=>1,'contact_name'=>'Legacy Upgrade','contact_email'=>'legacy-upgrade@example.invalid',
    'space'=>'Hall','attendees'=>1,'purpose'=>'Legacy upgrade','start_date'=>$date,'repeat_until'=>$date,'recurrence_rule'=>'FREQ=WEEKLY;INTERVAL=1',
    'schedule_json'=>wp_json_encode(array()),'price_per_booking'=>'10.00','estimated_total'=>'10.00','requested_count'=>1,'accepted_count'=>1,
    'billing_mode'=>'legacy_per_occurrence','billing_treatment'=>'legacy_per_occurrence','deposit_policy'=>'legacy_per_occurrence','payment_method'=>'online',
    'invoice_lead_days'=>365,'payment_terms_days'=>14,'billing_schedule_json'=>wp_json_encode(array()),'metadata_incomplete'=>1,
    'adoption_state'=>'eligible','created_at'=>$now,'updated_at'=>$now,
));
if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);
$wpdb->insert($booking_table,array(
    'ref'=>'INT-A-LEGACY-PAID','status'=>'paid','name'=>'Legacy Upgrade','email'=>'legacy-upgrade@example.invalid','phone'=>'000','address'=>'Test','space'=>'Hall',
    'booking_date'=>$date,'booking_date_end'=>$date,'attendees'=>1,'purpose'=>'Legacy upgrade','amount'=>'10.00','amount_paid'=>'10.00','deposit_paid'=>'0.00',
    'series_id'=>'INT-A-LEGACY-UPGRADE','legacy_billing_excluded'=>0,'created_at'=>$now,'updated_at'=>$now,
));
if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);

$wpdb->query("ALTER TABLE {$booking_table} DROP COLUMN legacy_billing_excluded");
update_option('mbs_db_version','3.21.0-schema-6',false);
$migrated=MBS_Database::create_tables();
mbs_audit_migration_assert(!is_wp_error($migrated),'Schema-6 upgrade failed: '.(is_wp_error($migrated)?$migrated->get_error_message():''));
$excluded=(int)$wpdb->get_var($wpdb->prepare("SELECT legacy_billing_excluded FROM {$booking_table} WHERE ref=%s",'INT-A-LEGACY-PAID'));
mbs_audit_migration_assert($excluded===1,'Already-registered historical paid occurrence was not excluded during upgrade.');
$adopted=MBS_Billing_Engine::configure_series('INT-A-LEGACY-UPGRADE',array(
    'billing_mode'=>'monthly','billing_treatment'=>'invoice_managed','payment_method'=>'online','deposit_policy'=>'none',
    'invoice_lead_days'=>365,'payment_terms_days'=>14,'billing_schedule'=>array(),'adopt_legacy'=>true,
),1);
mbs_audit_migration_assert(!is_wp_error($adopted),'Legacy adoption failed after the upgrade backfill.');
$preview=MBS_Billing_Engine::preview('INT-A-LEGACY-UPGRADE');
mbs_audit_migration_assert(!is_wp_error($preview)&&empty($preview['periods']),'Historically paid legacy occurrence became billable after adoption.');

// Keep the required names but deliberately corrupt uniqueness/composition. A
// migration may repair this definition or fail closed, but must never certify it.
$wpdb->query("ALTER TABLE {$reservation_table} DROP INDEX invoice_owner, ADD INDEX invoice_owner (invoice_ref)");
update_option('mbs_db_version','3.21.0-schema-7-malformed',false);
$block_repair=static function($query)use($reservation_table){
    if(stripos($query,'ALTER TABLE')!==false&&strpos($query,$reservation_table)!==false&&stripos($query,'invoice_owner')!==false)return 'SELECT 1';
    return $query;
};
add_filter('query',$block_repair);
$semantic=MBS_Database::create_tables();
remove_filter('query',$block_repair);
$index=$wpdb->get_results("SHOW INDEX FROM {$reservation_table} WHERE Key_name='invoice_owner'");
$is_exact=count($index)===1&&(int)$index[0]->Non_unique===0&&(string)$index[0]->Column_name==='invoice_id'&&(int)$index[0]->Seq_in_index===1;
mbs_audit_migration_assert(is_wp_error($semantic)||$is_exact,'Migration advanced despite a same-name, non-unique, wrong-column ownership index.');
mbs_audit_migration_assert(get_option('mbs_db_version')!=='3.21.0-schema-7-malformed'||$is_exact,'Malformed schema received the current version marker.');

// Restore the disposable database so the standard migration suite can continue.
$wpdb->query("ALTER TABLE {$reservation_table} DROP INDEX invoice_owner, ADD UNIQUE KEY invoice_owner (invoice_id)");
$restored=MBS_Database::create_tables();
if(is_wp_error($restored))throw new RuntimeException('Could not restore schema after semantic verification: '.$restored->get_error_message());
if($mbs_audit_migration_failures)throw new RuntimeException(count($mbs_audit_migration_failures)." adversarial migration regression(s) failed:\n- ".implode("\n- ",$mbs_audit_migration_failures));
echo "OK: registered legacy upgrade backfill and semantic schema verification passed.\n";
