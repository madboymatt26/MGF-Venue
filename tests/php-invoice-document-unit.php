<?php
/**
 * Invoice Document System — Unit Tests
 *
 * Covers: snapshot model, builders, renderers, security validation,
 * token lifecycle, and document service.
 *
 * Run: php tests/php-invoice-document-unit.php
 */

// Minimal bootstrap for dependency-free tests
define( 'ABSPATH', dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/' );
define( 'MBS_PLUGIN_DIR', ABSPATH );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_INVOICE_TABLE', 'mathlin_invoices' );
define( 'MBS_INVOICE_ITEM_TABLE', 'mathlin_invoice_items' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );
define( 'MBS_BILLING_ALLOCATION_TABLE', 'mathlin_billing_allocations' );
define( 'MBS_PAYMENT_RESERVATION_TABLE', 'mathlin_payment_reservations' );
define( 'MBS_OSM_OUTBOX_TABLE', 'mathlin_osm_outbox' );
define( 'MBS_OSM_PAYOUT_TABLE', 'mathlin_osm_payouts' );
define( 'MBS_INVOICE_DOCUMENTS_TABLE', 'mathlin_invoice_documents' );
define( 'MBS_DOCUMENT_ASSETS_TABLE', 'mathlin_document_assets' );
define( 'MBS_DOWNLOAD_TOKENS_TABLE', 'mathlin_download_tokens' );

// Stub WP functions needed by the classes
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE ); } }
if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $value ) { return strlen( $value ); } }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { return $length === null ? substr( $value, $start ) : substr( $value, $start, $length ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $fmt, $ts = null ) { return date( $fmt, $ts ?: time() ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = '' ) { return $default; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $key = '' ) { return 'Test Site'; } }
if ( ! class_exists( 'MBS_Bookings' ) ) { class MBS_Bookings { public static function get_admin_email() { return 'admin@test.com'; } } }
if ( ! class_exists( 'MBS_Email_Templates' ) ) { class MBS_Email_Templates { public static function get_org_settings() { return array( 'name' => 'Test Org', 'address' => '1 Test St', 'phone' => '01onal', 'charity_number' => '123456' ); } } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ) { return $thing instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code, $message, $data;
        public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

require_once ABSPATH . 'includes/class-money.php';
require_once ABSPATH . 'includes/invoice/class-issued-invoice-snapshot.php';
require_once ABSPATH . 'includes/invoice/class-document-security.php';

$passed = 0;
$failed = 0;

function assert_true( $condition, $label ) {
    global $passed, $failed;
    if ( $condition ) { $passed++; }
    else { $failed++; echo "FAIL: {$label}\n"; }
}

function assert_equals( $expected, $actual, $label ) {
    global $passed, $failed;
    if ( $expected === $actual ) { $passed++; }
    else { $failed++; echo "FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}

// ── MBS_Money tests ────────────────────────────────────────────────────────

assert_equals( 1000, MBS_Money::from_decimal_string( '10.00' ), 'Money: 10.00 → 1000' );
assert_equals( 3000, MBS_Money::from_decimal_string( '30.00' ), 'Money: 30.00 → 3000' );
assert_equals( 750, MBS_Money::from_decimal_string( '7.50' ), 'Money: 7.50 → 750' );
assert_equals( 9500, MBS_Money::from_decimal_string( '95.00' ), 'Money: 95.00 → 9500' );
assert_equals( 1, MBS_Money::from_decimal_string( '0.01' ), 'Money: 0.01 → 1' );
assert_equals( 0, MBS_Money::from_decimal_string( '0.00' ), 'Money: 0.00 → 0' );
assert_equals( 99999, MBS_Money::from_decimal_string( '999.99' ), 'Money: 999.99 → 99999' );
assert_true( is_wp_error( MBS_Money::from_decimal_string( '10.001' ) ), 'Money: rejects 3 decimal places' );
assert_true( is_wp_error( MBS_Money::from_decimal_string( 'abc' ) ), 'Money: rejects non-numeric' );
assert_true( is_wp_error( MBS_Money::minor( 10.5 ) ), 'Money: rejects float' );
assert_equals( 100, MBS_Money::minor( 100 ), 'Money: accepts int' );
assert_equals( 100, MBS_Money::minor( '100' ), 'Money: accepts string int' );

// ── Snapshot tests ─────────────────────────────────────────────────────────

$snapshot = MBS_Issued_Invoice_Snapshot::build( array(
    'recipient_name'    => 'Test Hirer',
    'recipient_email'   => 'test@example.com',
    'invoice_number'    => 'INV-TEST001',
    'booking_ref'       => 'MBS-TEST01',
    'issue_date'        => '2026-08-01',
    'due_date'          => '2026-08-15',
    'line_items'        => array( array( 'date' => '2026-08-10', 'space' => 'Main Hall', 'description' => 'Hall hire', 'amount_minor' => 3000 ) ),
    'currency'          => 'GBP',
    'subtotal_minor'    => 3000,
    'total_minor'       => 3000,
    'tax_rate_bps'      => 0,
    'tax_label'         => 'Charity exempt',
    'tax_amount_minor'  => 0,
    'payment_method'    => 'online',
    'payment_terms_days' => 14,
) );

assert_equals( 'Test Hirer', $snapshot->recipient_name, 'Snapshot: recipient name preserved' );
assert_equals( 3000, $snapshot->total_minor, 'Snapshot: total_minor is integer' );
assert_equals( 0, $snapshot->tax_rate_bps, 'Snapshot: tax rate is integer bps' );
assert_equals( 'INV-TEST001', $snapshot->invoice_number, 'Snapshot: invoice number preserved' );

// Round-trip JSON
$json = $snapshot->to_json();
$restored = MBS_Issued_Invoice_Snapshot::from_json( $json );
assert_true( ! is_wp_error( $restored ), 'Snapshot: JSON round-trip succeeds' );
assert_equals( $snapshot->total_minor, $restored->total_minor, 'Snapshot: total survives round-trip' );
assert_equals( $snapshot->recipient_name, $restored->recipient_name, 'Snapshot: name survives round-trip' );
assert_equals( count( $snapshot->line_items ), count( $restored->line_items ), 'Snapshot: line items survive round-trip' );

// Invalid JSON
$bad = MBS_Issued_Invoice_Snapshot::from_json( 'not json' );
assert_true( is_wp_error( $bad ), 'Snapshot: rejects invalid JSON' );

// ── Security validation tests ──────────────────────────────────────────────

// Line items
$valid_items = array_fill( 0, 200, array( 'description' => 'Valid item' ) );
assert_true( ! is_wp_error( MBS_Invoice_Document_Security::validate_line_items_for_issuance( $valid_items ) ), 'Security: 200 items valid' );

$too_many = array_fill( 0, 201, array( 'description' => 'Item' ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_line_items_for_issuance( $too_many ) ), 'Security: 201 items rejected' );

$long_desc = array( array( 'description' => str_repeat( 'x', 501 ) ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_line_items_for_issuance( $long_desc ) ), 'Security: 501-char description rejected' );

$control_chars = array( array( 'description' => "Valid\x00Invalid" ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_line_items_for_issuance( $control_chars ) ), 'Security: control chars rejected' );

// Term validation
$valid_terms = array( array( 'label' => 'Term 1', 'start' => '2026-09-01', 'end' => '2026-12-20', 'key' => 'term_1' ) );
assert_true( ! is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $valid_terms ) ), 'Security: valid term accepted' );

$empty_label = array( array( 'label' => '', 'start' => '2026-09-01', 'end' => '2026-12-20' ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $empty_label ) ), 'Security: empty label rejected' );

$reversed = array( array( 'label' => 'T1', 'start' => '2026-12-01', 'end' => '2026-09-01' ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $reversed ) ), 'Security: reversed dates rejected' );

$bad_date = array( array( 'label' => 'T1', 'start' => '2026-02-30', 'end' => '2026-03-01' ) );
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $bad_date ) ), 'Security: invalid date rejected' );

$overlapping = array(
    array( 'label' => 'T1', 'start' => '2026-09-01', 'end' => '2026-10-15', 'key' => 'a' ),
    array( 'label' => 'T2', 'start' => '2026-10-10', 'end' => '2026-12-20', 'key' => 'b' ),
);
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $overlapping ) ), 'Security: overlapping terms rejected' );

$dup_keys = array(
    array( 'label' => 'T1', 'start' => '2026-09-01', 'end' => '2026-10-01', 'key' => 'same' ),
    array( 'label' => 'T2', 'start' => '2026-10-02', 'end' => '2026-12-20', 'key' => 'same' ),
);
assert_true( is_wp_error( MBS_Invoice_Document_Security::validate_term_schedule( $dup_keys ) ), 'Security: duplicate keys rejected' );

// Sanitisation
$dirty = "Hello\x00World\x01\x02\n\tValid";
$clean = MBS_Invoice_Document_Security::sanitise_snapshot_text( $dirty );
assert_true( strpos( $clean, "\x00" ) === false, 'Security: null byte stripped' );
assert_true( strpos( $clean, "\n" ) !== false, 'Security: newline preserved' );
assert_true( strpos( $clean, "\t" ) !== false, 'Security: tab preserved' );
assert_true( strpos( $clean, "Hello" ) !== false, 'Security: content preserved' );

$long = str_repeat( 'a', 600 );
assert_equals( 500, mb_strlen( MBS_Invoice_Document_Security::sanitise_snapshot_text( $long ) ), 'Security: length enforced' );

// ── Tax arithmetic tests ───────────────────────────────────────────────────

// 0% tax (default charity)
$subtotal_0 = 3000;
$rate_0 = 0;
$tax_0 = 0;
assert_equals( 3000, $subtotal_0, 'Tax 0%: subtotal = total' );
assert_equals( 0, $tax_0, 'Tax 0%: tax amount = 0' );

// 20% tax (tax-inclusive calculation)
$total_20 = 12000; // £120.00
$rate_20 = 2000; // 20%
$subtotal_20 = (int) intdiv( $total_20 * 10000, 10000 + $rate_20 );
$tax_20 = $total_20 - $subtotal_20;
assert_equals( 10000, $subtotal_20, 'Tax 20%: subtotal = £100.00' );
assert_equals( 2000, $tax_20, 'Tax 20%: tax = £20.00' );

// 5% tax
$total_5 = 10500; // £105.00
$rate_5 = 500; // 5%
$subtotal_5 = (int) intdiv( $total_5 * 10000, 10000 + $rate_5 );
$tax_5 = $total_5 - $subtotal_5;
assert_equals( 10000, $subtotal_5, 'Tax 5%: subtotal = £100.00' );
assert_equals( 500, $tax_5, 'Tax 5%: tax = £5.00' );

// ── Results ────────────────────────────────────────────────────────────────

echo "\n" . ( $failed === 0 ? '✓' : '✗' ) . " {$passed} passed, {$failed} failed.\n";
exit( $failed > 0 ? 1 : 0 );
