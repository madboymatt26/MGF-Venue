<?php
define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'MBS_VERSION', '3.21.0-test' );
define( 'MBS_DB_VERSION', '3.21.0-schema-2-test' );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_INVOICE_TABLE', 'mathlin_invoices' );
define( 'MBS_INVOICE_ITEM_TABLE', 'mathlin_invoice_items' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );
define( 'MBS_BILLING_ALLOCATION_TABLE', 'mathlin_billing_allocations' );

$mbs_test_dbdelta_calls = array();
$mbs_test_created_tables = array();
$mbs_test_options = array( 'mbs_woo_product_renamed' => true );

class MBS_Test_WPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_row( $sql ) { return (object) array( 'Type' => 'varchar(20)' ); }
    public function get_results( $sql ) { return array( (object) array( 'present' => true ) ); }
    public function query( $sql ) { return true; }
}
$wpdb = new MBS_Test_WPDB();

function get_option( $key, $default = false ) { global $mbs_test_options; return array_key_exists( $key, $mbs_test_options ) ? $mbs_test_options[ $key ] : $default; }
function update_option( $key, $value ) { global $mbs_test_options; $mbs_test_options[ $key ] = $value; return true; }

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-database.php';
MBS_Database::create_tables();
MBS_Database::create_tables();

$required = array(
    'wp_mathlin_bookings', 'wp_mathlin_booking_series', 'wp_mathlin_invoices',
    'wp_mathlin_invoice_items', 'wp_mathlin_payment_transactions',
    'wp_mathlin_billing_allocations', 'wp_mathlin_blocked_dates',
    'wp_mathlin_audit_log', 'wp_mathlin_email_queue', 'wp_mathlin_mod_requests',
);
foreach ( $required as $table ) {
    if ( empty( $mbs_test_created_tables[ $table ] ) || $mbs_test_dbdelta_calls[ $table ] !== 2 ) {
        fwrite( STDERR, "FAIL: {$table} was not safely presented to dbDelta on both migration runs.\n" );
        exit( 1 );
    }
}
if ( count( $mbs_test_created_tables ) !== count( $required ) ) {
    fwrite( STDERR, "FAIL: Unexpected or missing migrated table definitions.\n" );
    exit( 1 );
}
echo 'OK: migration completed twice across ' . count( $required ) . " additive tables.\n";
