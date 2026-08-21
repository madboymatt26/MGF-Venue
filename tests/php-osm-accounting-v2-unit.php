<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $hook, $value ) { return $value; }

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-money.php';
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-osm-accounting-v2.php';

$settings = array(
    'venue_category_id' => '10', 'venue_item_id' => '100',
    'clothing_category_id' => '20', 'clothing_item_id' => '200',
    'fees_category_id' => '30', 'fees_item_id' => '300',
    'product_mappings' => array(), 'description_tpl' => 'Woo payout {payout_id}',
);

function osm_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$mixed = MBS_OSM_Integration::classify_payout(
    array( 'id' => 'po_mixed', 'date' => '2026-08-20', 'amount' => 1850, 'currency' => 'GBP' ),
    array(
        array( 'id' => 'tx_venue', 'type' => 'charge', 'mapping_key' => 'venue', 'amount' => 1200, 'fee' => 36, 'currency' => 'GBP', 'order_id' => 11 ),
        array( 'id' => 'tx_clothing', 'type' => 'charge', 'mapping_key' => 'clothing', 'amount' => 700, 'fee' => 14, 'currency' => 'GBP', 'order_id' => 12 ),
    ),
    $settings
);
osm_assert( ! is_wp_error( $mixed ), 'Mixed venue/clothing payout did not classify.' );
osm_assert( $mixed['amount'] === 1850 && count( $mixed['categories'] ) === 3, 'Mixed payout did not create one net payout with three category lines.' );
osm_assert( $mixed['component_order_ids'] === array( 11, 12 ), 'Payout component orders were not retained for ledger consolidation.' );
osm_assert( $mixed['schema'] === 3 && count( $mixed['components'] ) === 2, 'Payout component audit snapshots were not retained.' );
osm_assert( $mixed['components'][0]['gross_minor'] === 1200 && $mixed['components'][0]['fee_minor'] === 36 && $mixed['components'][0]['net_minor'] === 1164, 'Component gross/fee/net audit calculation is incorrect.' );

$with_refund = MBS_OSM_Integration::classify_payout(
    array( 'id' => 'po_refund', 'date' => '2026-08-21', 'amount' => 1650, 'currency' => 'GBP' ),
    array(
        array( 'type' => 'charge', 'mapping_key' => 'venue', 'amount' => 1200, 'fee' => 36, 'currency' => 'GBP', 'order_id' => 21 ),
        array( 'type' => 'charge', 'mapping_key' => 'clothing', 'amount' => 700, 'fee' => 14, 'currency' => 'GBP', 'order_id' => 22 ),
        array( 'type' => 'refund', 'mapping_key' => 'venue', 'amount' => 200, 'fee' => 0, 'currency' => 'GBP', 'order_id' => 21 ),
    ),
    $settings
);
osm_assert( ! is_wp_error( $with_refund ) && $with_refund['amount'] === 1650, 'Refund was not netted into its containing payout.' );
$venue = array_values( array_filter( $with_refund['categories'], static function ( $line ) { return $line['category_id'] === 10; } ) );
osm_assert( count( $venue ) === 1 && $venue[0]['amount'] === 1000, 'Refund did not reduce venue income rather than create a duplicate bank entry.' );

$mismatch = MBS_OSM_Integration::classify_payout(
    array( 'id' => 'po_bad', 'date' => '2026-08-21', 'amount' => 999, 'currency' => 'GBP' ),
    array( array( 'type' => 'charge', 'mapping_key' => 'venue', 'amount' => 1000, 'fee' => 25, 'currency' => 'GBP' ) ),
    $settings
);
osm_assert( is_wp_error( $mismatch ) && $mismatch->get_error_code() === 'payout_net_mismatch', 'A payout total mismatch did not fail closed.' );

$missing_fee = $settings; $missing_fee['fees_category_id'] = '';
$fee_error = MBS_OSM_Integration::classify_payout(
    array( 'id' => 'po_fee', 'date' => '2026-08-21', 'amount' => 975, 'currency' => 'GBP' ),
    array( array( 'type' => 'charge', 'mapping_key' => 'venue', 'amount' => 1000, 'fee' => 25, 'currency' => 'GBP' ) ),
    $missing_fee
);
osm_assert( is_wp_error( $fee_error ) && $fee_error->get_error_code() === 'fee_mapping_incomplete', 'Unmapped WooPayments fees did not stop reconciliation.' );

$nested = MBS_OSM_Integration::classify_payout(
    array( 'id' => 'po_nested', 'arrival_date' => '2026-08-21T12:00:00Z', 'amount' => array( 'amount' => 975, 'currency' => 'GBP' ) ),
    array( array( 'type' => 'charge', 'mapping_key' => 'venue', 'amount' => array( 'amount' => 1000, 'currency' => 'GBP' ), 'fee_amount' => array( 'amount' => -25 ), 'order' => array( 'id' => 31 ) ) ),
    $settings
);
osm_assert( ! is_wp_error( $nested ) && $nested['amount'] === 975 && $nested['component_order_ids'] === array( 31 ), 'Nested WooPayments money/order fields or signed fees were not normalised.' );
osm_assert( MBS_OSM_Integration::status_label( 'awaiting_bank_import' ) === 'Awaiting Co-op bank import', 'Normal bank-import waiting state does not have clear admin copy.' );

echo "OSM_ACCOUNTING_V2_UNIT_OK\n";
