<?php
global $wpdb;
require_once __DIR__ . '/audit-assertions.php';

function mbs_audit_migration_assert($condition,$message){
    MBS_Audit_Assertions::current()->check( $condition, $message );
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
mbs_audit_migration_assert(get_option('mbs_db_version')!==MBS_DB_VERSION||$is_exact,'Malformed schema received the current version marker.');

// Restore the disposable database so the standard migration suite can continue.
$wpdb->query("ALTER TABLE {$reservation_table} DROP INDEX invoice_owner, ADD UNIQUE KEY invoice_owner (invoice_id)");
$restored=MBS_Database::create_tables();
if(is_wp_error($restored))throw new RuntimeException('Could not restore schema after semantic verification: '.$restored->get_error_message());

// Financial history can exist even when mutable legacy booking fields look
// unpaid. An invoice item alone is immutable evidence that the occurrence has
// already entered the financial ledger and must never be initially rebilled.
$financial_only_ref='INT-A-LEDGER-ONLY';
$wpdb->insert($booking_table,array(
    'ref'=>$financial_only_ref,'status'=>'confirmed','name'=>'Ledger Evidence','email'=>'ledger-evidence@example.invalid','phone'=>'000','address'=>'Test','space'=>'Hall',
    'booking_date'=>$date,'booking_date_end'=>$date,'attendees'=>1,'purpose'=>'Ledger-only evidence','amount'=>'10.00','amount_paid'=>'0.00','deposit_paid'=>'0.00',
    'series_id'=>'INT-A-LEGACY-UPGRADE','legacy_billing_excluded'=>0,'created_at'=>$now,'updated_at'=>$now,
));
if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);
$draft=MBS_Billing_Ledger::create_draft_invoice(array(
    'contact_name'=>'Ledger Evidence','contact_email'=>'ledger-evidence@example.invalid','contact_address'=>'Test','billing_mode'=>'monthly','currency'=>'GBP',
    'period_start'=>$date,'period_end'=>$date,'due_at'=>wp_date('Y-m-d H:i:s',strtotime('+14 days')),
),'audit-ledger-only-history');
if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
$item=MBS_Billing_Ledger::add_item($draft['invoice']->invoice_ref,array(
    'item_type'=>'hire','booking_ref'=>$financial_only_ref,'service_date'=>$date,'description'=>'Immutable ledger evidence',
    'quantity_milli'=>1000,'unit_amount_minor'=>1000,'pricing_snapshot'=>array('audit'=>true),
),(int)$draft['invoice']->version);
if(is_wp_error($item))throw new RuntimeException($item->get_error_message());
update_option('mbs_db_version','3.21.0-schema-6-ledger-evidence',false);
$ledger_backfill=MBS_Database::create_tables();
$ledger_excluded=(int)$wpdb->get_var($wpdb->prepare("SELECT legacy_billing_excluded FROM {$booking_table} WHERE ref=%s",$financial_only_ref));
mbs_audit_migration_assert(!is_wp_error($ledger_backfill),'Ledger-evidence backfill migration failed unexpectedly.');
mbs_audit_migration_assert($ledger_excluded===1,'Occurrence evidenced only by an invoice item remained eligible for consolidated rebilling.');

// A backfill error must stop marker advancement and remain retryable.
$wpdb->update($booking_table,array('legacy_billing_excluded'=>0),array('ref'=>'INT-A-LEGACY-PAID'));
$old_marker='3.21.0-schema-6-backfill-fault';
update_option('mbs_db_version',$old_marker,false);
$block_backfill=static function($query){
    if(stripos($query,'SET b.legacy_billing_excluded=1')!==false)return 'UPDATE missing_mbs_audit_table SET broken=1';
    return $query;
};
add_filter('query',$block_backfill);
$failed_backfill=MBS_Database::create_tables();
remove_filter('query',$block_backfill);
$failure_state=get_option('mbs_migration_state',array());
mbs_audit_migration_assert(is_wp_error($failed_backfill),'A failed legacy exclusion update did not fail the migration.');
mbs_audit_migration_assert(get_option('mbs_db_version')===$old_marker,'The schema marker advanced after a failed legacy exclusion update.');
mbs_audit_migration_assert(($failure_state['status']??'')==='failed','The migration failure was not stored for administrators.');
$retry=MBS_Database::create_tables();
mbs_audit_migration_assert(!is_wp_error($retry)&&get_option('mbs_db_version')===MBS_DB_VERSION,'The failed legacy backfill was not safely retryable.');

// A same-name malformed currency column must be repaired or block the marker.
$invoice_table=$wpdb->prefix.MBS_INVOICE_TABLE;
$wpdb->query("ALTER TABLE {$invoice_table} MODIFY currency VARCHAR(4) NULL DEFAULT NULL");
$old_marker='3.21.0-schema-7-malformed-column';
update_option('mbs_db_version',$old_marker,false);
$block_currency_repair=static function($query)use($invoice_table){
    if(stripos($query,'ALTER TABLE')!==false&&strpos($query,$invoice_table)!==false&&stripos($query,'currency')!==false)return 'SELECT 1';
    return $query;
};
add_filter('query',$block_currency_repair);
$column_semantic=MBS_Database::create_tables();
remove_filter('query',$block_currency_repair);
$currency=$wpdb->get_row("SHOW FULL COLUMNS FROM {$invoice_table} WHERE Field='currency'");
$currency_exact=$currency&&strtolower((string)$currency->Type)==='char(3)'&&(string)$currency->Null==='NO'&&(string)$currency->Default==='GBP';
mbs_audit_migration_assert(is_wp_error($column_semantic)||$currency_exact,'Migration certified a same-name malformed currency column.');
mbs_audit_migration_assert(get_option('mbs_db_version')!==MBS_DB_VERSION||$currency_exact,'Malformed currency column received the current marker.');
$wpdb->query("ALTER TABLE {$invoice_table} MODIFY currency CHAR(3) NOT NULL DEFAULT 'GBP'");
$restored=MBS_Database::create_tables();
if(is_wp_error($restored))throw new RuntimeException('Could not restore schema after column verification: '.$restored->get_error_message());

MBS_Audit_Assertions::current()->finish( 'registered legacy upgrade, fail-closed migration, and semantic schema verification passed' );
