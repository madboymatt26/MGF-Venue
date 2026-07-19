<?php
define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'MBS_VERSION', '3.21.0-test' );
define( 'MBS_DB_VERSION', '3.21.0-schema-6-test' );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_INVOICE_TABLE', 'mathlin_invoices' );
define( 'MBS_INVOICE_ITEM_TABLE', 'mathlin_invoice_items' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );
define( 'MBS_BILLING_ALLOCATION_TABLE', 'mathlin_billing_allocations' );

$mbs_test_dbdelta_calls = array();
$mbs_test_created_tables = array();
$mbs_test_table_sql = array();
$mbs_test_options = array( 'mbs_woo_product_renamed' => true );
$mbs_test_missing_column = '';

class WP_Error { public function __construct( private $code, private $message ) {} public function get_error_message(){ return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class MBS_Test_WPDB {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_row( $sql ) { return (object) array( 'Type' => 'varchar(20)' ); }
    public function prepare( $sql, ...$args ) { if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0]; foreach ( $args as $arg ) $sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 ); return $sql; }
    public function get_var( $sql ) { global $mbs_test_created_tables; if ( preg_match( "/SHOW TABLES LIKE '?([^']+)'?/i", $sql, $m ) ) return ! empty( $mbs_test_created_tables[$m[1]] ) ? $m[1] : null; return null; }
    public function get_col( $sql ) { global $mbs_test_table_sql, $mbs_test_missing_column; preg_match( '/FROM `([^`]+)`/', $sql, $m ); $ddl=$mbs_test_table_sql[$m[1]]??''; preg_match_all( '/^\s*([a-z_][a-z0-9_]*)\s+(?:BIGINT|VARCHAR|CHAR|TEXT|LONGTEXT|DATE|DATETIME|TIME|TINYINT|SMALLINT|DECIMAL)/mi',$ddl,$cols); return array_values(array_filter($cols[1],fn($c)=>$c!==$mbs_test_missing_column)); }
    public function get_results( $sql ) { global $mbs_test_table_sql; if(preg_match("/SHOW COLUMNS FROM ([^ ]+) LIKE '([^']+)'/i",$sql,$m)){return preg_match('/^\\s*'.preg_quote($m[2],'/').'\\s+/mi',$mbs_test_table_sql[trim($m[1],'`')]??'')?array((object)array('present'=>true)):array();} if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $sql, $m ) ) { $ddl=$mbs_test_table_sql[$m[1]]??''; preg_match_all('/(?:PRIMARY KEY|(?:UNIQUE )?KEY)\s*(?:([a-z_][a-z0-9_]*)\s*)?\(/i',$ddl,$keys); return array_map(fn($k)=>(object)array('Key_name'=>$k?:'PRIMARY'),$keys[1]); } return array( (object) array( 'present' => true ) ); }
    public function query( $sql ) { global $mbs_test_table_sql; if(preg_match('/ALTER TABLE\s+([^\s]+)\s+ADD COLUMN\s+([a-z_][a-z0-9_]*)\s+([^;]+)/i',$sql,$m)){$mbs_test_table_sql[trim($m[1],'`')] .= "\n{$m[2]} {$m[3]}";} return true; }
}
$wpdb = new MBS_Test_WPDB();

function get_option( $key, $default = false ) { global $mbs_test_options; return array_key_exists( $key, $mbs_test_options ) ? $mbs_test_options[ $key ] : $default; }
function update_option( $key, $value ) { global $mbs_test_options; $mbs_test_options[ $key ] = $value; return true; }
function add_option( $key, $value ) { global $mbs_test_options; if(array_key_exists($key,$mbs_test_options)) return false; $mbs_test_options[$key]=$value; return true; }
function delete_option( $key ) { global $mbs_test_options; unset($mbs_test_options[$key]); return true; }
function wp_generate_password(){ return 'migration-test-token'; }
function current_time(){ return '2026-07-19 12:00:00'; }
function add_action(){}
function esc_html($v){return $v;}

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-database.php';
$first = MBS_Database::create_tables();
$second = MBS_Database::create_tables();
if ( $first !== true || $second !== true || get_option('mbs_db_version') !== MBS_DB_VERSION ) { fwrite(STDERR,"FAIL: repeated migration did not verify and advance the marker: " . (is_wp_error($first)?$first->get_error_message():(is_wp_error($second)?$second->get_error_message():'marker mismatch')) . "\n"); exit(1); }

$required = array(
    'wp_mathlin_bookings', 'wp_mathlin_booking_series', 'wp_mathlin_invoices',
    'wp_mathlin_invoice_items', 'wp_mathlin_payment_transactions',
    'wp_mathlin_billing_allocations', 'wp_mathlin_blocked_dates',
    'wp_mathlin_audit_log', 'wp_mathlin_email_queue', 'wp_mathlin_mod_requests',
);
foreach ( $required as $table ) {
    if ( empty( $mbs_test_created_tables[ $table ] ) || $mbs_test_dbdelta_calls[ $table ] !== 2 ) {
        fwrite( STDERR, "FAIL: {$table} was not safely presented to dbDelta on both initial migration runs.\n" );
        exit( 1 );
    }
}
if ( count( $mbs_test_created_tables ) !== count( $required ) ) {
    fwrite( STDERR, "FAIL: Unexpected or missing migrated table definitions.\n" );
    exit( 1 );
}
$mbs_test_options['mbs_db_version'] = 'old';
$mbs_test_missing_column = 'idempotency_request_hash';
$failed = MBS_Database::create_tables();
if ( ! is_wp_error($failed) || get_option('mbs_db_version') !== 'old' || get_option('mbs_migration_state')['status'] !== 'failed' ) { fwrite(STDERR,"FAIL: verification failure advanced the marker or was not visible.\n"); exit(1); }
$mbs_test_missing_column = '';
$retried = MBS_Database::create_tables();
if ( $retried !== true || get_option('mbs_db_version') !== MBS_DB_VERSION ) { fwrite(STDERR,"FAIL: failed migration was not retryable.\n"); exit(1); }
echo 'OK: 14 migration assertions passed (repeated execution, 10 tables, failure retention and retry).'."\n";
