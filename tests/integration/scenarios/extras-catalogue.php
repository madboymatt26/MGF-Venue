<?php
/** Synthetic records only; this scenario must run in the disposable test WordPress. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'MBS_Extras_Catalogue' ) ) throw new RuntimeException( 'Load the development build in disposable WordPress.' );
if ( strpos( home_url(), 'localhost' ) === false && strpos( home_url(), 'wordpress' ) === false && strpos( home_url(), '127.0.0.1' ) === false ) throw new RuntimeException( 'Refusing non-test host.' );
global $wpdb;
$checks = 0;
$assert = static function( $ok, $label ) use ( &$checks ) { ++$checks; if ( ! $ok ) throw new RuntimeException( $label ); };
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
if ( ! $admins ) throw new RuntimeException( 'Disposable WordPress needs a test administrator.' );
wp_set_current_user( $admins[0] );
$options = array();
foreach ( array( MBS_Extras_Catalogue::OPTION, 'mbs_payments_enabled', 'mbs_payment_feature_invoices', 'mbs_payment_feature_additional_charges', 'mbs_campsite_mode' ) as $name ) $options[$name] = get_option( $name, null );
$ref = 'EXT-' . substr( wp_generate_uuid4(), 0, 12 ); $invoice = null;
$outbox_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mathlin_email_queue' );
try {
    delete_option( MBS_Extras_Catalogue::OPTION );
    update_option( 'mbs_campsite_mode', 0 ); update_option( 'mbs_payments_enabled', 1 ); update_option( 'mbs_payment_feature_invoices', 1 ); update_option( 'mbs_payment_feature_additional_charges', 1 );
    $assert( ! MBS_Extras_Catalogue::usable(), 'Optional feature starts disabled.' );
    $input = array( 'operation' => 'save', 'revision' => 0, 'description' => 'Synthetic cleaning', 'unit' => 'visit', 'price' => '25.00', 'category' => 'cleaning', 'account_code' => '4000', 'tax_code' => '' );
    $state = MBS_Extras_Catalogue::save( $input );
    $assert( ! is_wp_error( $state ) && $state['revision'] === 1, 'Real option creation succeeds.' );
    $id = key( $state['items'] );
    $assert( is_wp_error( MBS_Extras_Catalogue::save( $input ) ), 'Real stale revision rejected.' );
    $state = MBS_Extras_Catalogue::save( array( 'operation' => 'enable', 'revision' => 1, 'enabled' => true ) );
    $assert( ! is_wp_error( $state ) && MBS_Extras_Catalogue::usable(), 'Real CAS enables module.' );
    $assert( $wpdb->insert( $wpdb->prefix . MBS_TABLE, array( 'ref' => $ref, 'status' => 'pending', 'name' => 'Synthetic extra test', 'email' => 'extras@example.invalid', 'space' => 'Main Hall', 'booking_date' => '2027-07-01', 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'purpose' => 'Synthetic extras test', 'amount' => '0.00' ) ) === 1, 'Synthetic pending booking inserted.' );
    $data = array( 'related_ref' => $ref, 'catalogue_id' => $id, 'catalogue_version' => 1, 'catalogue_quantity' => 3,
        'amount' => '0.01', 'description' => 'Untrusted caller override', 'category' => 'damage',
        'service_date' => '2027-07-01', 'due_date' => '2027-07-15', 'contact_name' => 'Synthetic extra test', 'contact_email' => 'extras@example.invalid' );
    $key = wp_generate_uuid4();
    $invoice = MBS_Additional_Charges::create( $data, $key );
    $assert( ! is_wp_error( $invoice ) && $invoice->status === 'draft', 'Catalogue creates a draft only.' );
    $items = MBS_Billing_Ledger::get_items( $invoice->id );
    $assert( count( $items ) === 1 && (int) $items[0]->unit_amount_minor === 2500 && (int) $items[0]->quantity_milli === 3000 && (int) $items[0]->line_total_minor === 7500, 'Real ledger stores exact server-calculated quantity and price.' );
    $snapshot = json_decode( $items[0]->pricing_snapshot_json, true );
    $assert( $snapshot['related_ref'] === $ref && $snapshot['category'] === 'cleaning' && $snapshot['catalogue']['account_code'] === '4000', 'Immutable item retains booking association, configured category and mapping.' );
    $assert( strpos( $items[0]->description, 'Untrusted' ) === false, 'Caller cannot override catalogue description.' );
    $replay = MBS_Additional_Charges::create( $data, $key );
    $assert( ! is_wp_error( $replay ) && $replay->id === $invoice->id, 'Repeat stable request creates no duplicate charge.' );
    $state = MBS_Extras_Catalogue::save( array_merge( $input, array( 'revision' => 2, 'id' => $id, 'price' => '30.00' ) ) );
    $assert( ! is_wp_error( $state ) && is_wp_error( MBS_Additional_Charges::create( $data, wp_generate_uuid4() ) ), 'Changed catalogue selection requires a new acknowledgement.' );
    $assert( (int) MBS_Billing_Ledger::get_items( $invoice->id )[0]->line_total_minor === 7500, 'Editing catalogue does not rewrite an existing draft.' );
    $edit = array_merge( $data, array( 'amount' => '80.00', 'description' => 'Explicit revised agreement' ) );
    $edited = MBS_Additional_Charges::update_draft( $invoice->invoice_ref, $edit, (int) $replay->version );
    $assert( ! is_wp_error( $edited ), 'Explicit draft custom-total edit succeeds.' );
    $items = MBS_Billing_Ledger::get_items( $invoice->id ); $meta = json_decode( $items[0]->pricing_snapshot_json, true );
    $assert( (int) $items[0]->quantity_milli === 1000 && (int) $items[0]->line_total_minor === 8000 && ! isset( $meta['catalogue'] ) && isset( $meta['catalogue_origin'] ), 'Manual edit resets arithmetic and preserves origin without mislabelling current price.' );
    wp_set_current_user( 0 );
    $assert( is_wp_error( MBS_Extras_Catalogue::save( array_merge( $input, array( 'revision' => 3 ) ) ) ), 'Anonymous pricing write refused.' );
    wp_set_current_user( $admins[0] );
    update_option( 'mbs_payments_enabled', 0 );
    $assert( ! MBS_Extras_Catalogue::usable() && is_wp_error( MBS_Additional_Charges::create( $data, wp_generate_uuid4() ) ), 'Free-site parent switch prevents new catalogue charges.' );
    $assert( MBS_Bookings::get( $ref )->status === 'pending', 'Charge never approves or otherwise changes its booking.' );
    $assert( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mathlin_email_queue' ) === $outbox_before, 'No customer message queued by configuration/draft operations.' );
    echo "EXTRAS_CATALOGUE_WP_OK ($checks assertions)\n";
} finally {
    if ( $invoice && ! is_wp_error( $invoice ) ) {
        $wpdb->delete( $wpdb->prefix . MBS_INVOICE_ITEM_TABLE, array( 'invoice_id' => $invoice->id ) );
        $wpdb->delete( $wpdb->prefix . MBS_INVOICE_TABLE, array( 'id' => $invoice->id ) );
        $wpdb->delete( $wpdb->prefix . 'mathlin_audit_log', array( 'ref' => $invoice->invoice_ref ) );
    }
    $wpdb->delete( $wpdb->prefix . MBS_TABLE, array( 'ref' => $ref ) );
    // Keep unrelated test audit history intact, including earlier catalogue configuration.
    foreach ( $options as $name => $value ) { if ( $value === null ) delete_option( $name ); else update_option( $name, $value, false ); }
    wp_set_current_user( $original_user ); wp_cache_flush();
}
