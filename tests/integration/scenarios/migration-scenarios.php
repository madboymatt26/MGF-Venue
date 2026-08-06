<?php
global $wpdb;

function mbs_migration_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

function mbs_migration_run( $legacy_marker ) {
    update_option( 'mbs_db_version', $legacy_marker, false );
    $result = MBS_Database::create_tables();
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    mbs_migration_assert( $result === true && get_option('mbs_db_version') === MBS_DB_VERSION, 'Migration did not verify before advancing its marker.' );
}

// Repeated current-schema migration is idempotent.
$first = MBS_Database::create_tables();
$second = MBS_Database::create_tables();
mbs_migration_assert( $first === true && $second === true, 'Repeated migration was not idempotent.' );

// Emulate the additive schema-5 financial shape.
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
$wpdb->query( "ALTER TABLE {$invoice_table} DROP COLUMN idempotency_request_hash" );
$wpdb->query( "ALTER TABLE {$transaction_table} DROP INDEX idx_transaction_parent, DROP COLUMN idempotency_request_hash, DROP COLUMN parent_transaction_id, DROP COLUMN refunded_minor" );
mbs_migration_run( '3.21.0-schema-5' );
foreach ( array('idempotency_request_hash') as $column ) mbs_migration_assert( (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$invoice_table} LIKE %s",$column)), 'Schema-5 invoice column was not upgraded.' );
foreach ( array('idempotency_request_hash','parent_transaction_id','refunded_minor') as $column ) mbs_migration_assert( (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$transaction_table} LIKE %s",$column)), 'Schema-5 transaction column was not upgraded: '.$column );

// Emulate schema-6 before durable reservations and payment-token metadata.
$reservation_table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;
$wpdb->query( "DROP TABLE {$reservation_table}" );
$wpdb->query( "ALTER TABLE {$invoice_table} DROP COLUMN payment_token_hash, DROP COLUMN payment_token_created_at" );
mbs_migration_run( '3.21.0-schema-6' );
mbs_migration_assert( $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$reservation_table)) === $reservation_table, 'Schema-6 reservation table was not created.' );
foreach ( array('payment_token_hash','payment_token_created_at') as $column ) mbs_migration_assert( (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$invoice_table} LIKE %s",$column)), 'Schema-6 token column was not upgraded.' );

// Partial column/index state and a legacy MyISAM booking table are repaired.
$booking_table = $wpdb->prefix . MBS_TABLE;
$allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
$wpdb->query( "ALTER TABLE {$booking_table} ENGINE=MyISAM" );
$wpdb->query( "ALTER TABLE {$allocation_table} DROP INDEX idx_allocation_booking" );
mbs_migration_run( '3.21.0-schema-7-partial' );
$engine = strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$booking_table)));
mbs_migration_assert( $engine === 'innodb', 'Legacy MyISAM booking table was not converted to InnoDB.' );
mbs_migration_assert( (bool)$wpdb->get_var("SHOW INDEX FROM {$allocation_table} WHERE Key_name='idx_allocation_booking'"), 'Partial index state was not repaired.' );

// A failed migration retains the old marker and becomes cleanly retryable.
$wpdb->query( "DROP TABLE {$reservation_table}" );
update_option( 'mbs_db_version', '3.21.0-schema-6-failed', false );
$break_create = static function( $query ) use ( $reservation_table ) {
    if ( stripos($query,'CREATE TABLE') !== false && strpos($query,$reservation_table) !== false ) return 'CREATE TABLE deliberately_invalid (';
    return $query;
};
add_filter( 'query', $break_create );
$failed = MBS_Database::create_tables();
remove_filter( 'query', $break_create );
$failed_state = get_option( 'mbs_migration_state', array() );
mbs_migration_assert( is_wp_error($failed) && get_option('mbs_db_version') === '3.21.0-schema-6-failed' && ($failed_state['status']??'') === 'failed', 'Failed migration advanced its marker or lost failure health.' );
$retried = MBS_Database::create_tables();
mbs_migration_assert( $retried === true && get_option('mbs_db_version') === MBS_DB_VERSION, 'Failed migration was not retryable.' );

// Force a real SQL error inside add_item and prove all transactional effects roll back.
$booking_ref = 'INT-MIG-ROLLBACK';
$date = wp_date('Y-m-d',strtotime('+30 days'));
$wpdb->insert( $booking_table, array(
    'ref'=>$booking_ref,'status'=>'confirmed','name'=>'Rollback','email'=>'rollback@example.invalid','phone'=>'000','address'=>'Test','space'=>'Hall',
    'booking_date'=>$date,'booking_date_end'=>$date,'attendees'=>1,'purpose'=>'Rollback','amount'=>'10.00','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
) );
$draft = MBS_Billing_Ledger::create_draft_invoice(array('contact_name'=>'Rollback','contact_email'=>'rollback@example.invalid','billing_mode'=>'monthly','currency'=>'GBP'),'migration-rollback-invoice');
if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
$draft_invoice=$draft['invoice'];
$item_table=$wpdb->prefix.MBS_INVOICE_ITEM_TABLE;
$break_item=static function($query)use($item_table){if(stripos($query,'INSERT INTO')!==false&&strpos($query,$item_table)!==false)return 'INSERT INTO missing_invoice_items VALUES (1)';return $query;};
add_filter('query',$break_item);
$rolled=MBS_Billing_Ledger::add_item($draft_invoice->invoice_ref,array('booking_ref'=>$booking_ref,'service_date'=>$date,'description'=>'Rollback','quantity_milli'=>1000,'unit_amount_minor'=>1000),1);
remove_filter('query',$break_item);
$draft_fresh=MBS_Billing_Ledger::get_invoice($draft_invoice->invoice_ref);
mbs_migration_assert(is_wp_error($rolled)&&(int)$draft_fresh->version===1&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$item_table} WHERE invoice_id=%d",$draft_invoice->id))===0&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$allocation_table} WHERE invoice_id=%d",$draft_invoice->id))===0,'Actual InnoDB item failure did not fully roll back.');

// Seed the barrier used by independent migration processes.
$barrier = $wpdb->prefix . 'mbs_test_migration_barrier';
$wpdb->query( "DROP TABLE IF EXISTS {$barrier}" );
$wpdb->query( "CREATE TABLE {$barrier} (id INT NOT NULL PRIMARY KEY, acquired INT NOT NULL DEFAULT 0, release_holder INT NOT NULL DEFAULT 0) ENGINE=InnoDB" );
$wpdb->insert( $barrier, array('id'=>1,'acquired'=>0,'release_holder'=>0) );
echo "OK: repeated, schema-5/6, partial, MyISAM, failure/retry, marker, and real rollback migrations passed.\n";
