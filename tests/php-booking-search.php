<?php
// Query-contract regression: no production database, booking or mail writes.
define( 'ABSPATH', __DIR__ );
define( 'MBS_TABLE', 'bookings' );
define( 'MBS_SERIES_TABLE', 'series' );
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function sanitize_key( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
class Booking_Search_DB {
    public $prefix = 'test_';
    public $query;
    public $values;
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function prepare( $query, $values ) { $this->query = $query; $this->values = $values; return $query; }
    public function get_results( $query ) { return array(); }
}
$wpdb = new Booking_Search_DB();
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-bookings.php';
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-series.php';
$checks = 0;
function search_check( $condition, $message ) {
    global $checks;
    $checks++;
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
MBS_Bookings::get_all( array( 'search' => 'Frostbite', 'exclude_scout' => true, 'limit' => 51, 'offset' => 50 ) );
search_check( strpos( $wpdb->query, 'OR notes LIKE %s)' ) !== false, 'Descriptions/notes searched alongside existing fields.' );
search_check( array_slice( $wpdb->values, 0, 5 ) === array_fill( 0, 5, '%Frostbite%' ), 'All five fields use the same substring term.' );
search_check( strpos( $wpdb->query, "status != 'archived' AND scout_use = 0 AND (" ) !== false, 'OR notes cannot bypass archive or Scout filters.' );
search_check( array_slice( $wpdb->values, -2 ) === array( 51, 50 ), 'Search remains before pagination.' );
MBS_Bookings::get_all( array( 'search' => "Camp'_%\\", 'status' => 'confirmed', 'date_from' => '2027-01-01' ) );
search_check( $wpdb->values[2] === "%Camp'\\_\\%\\\\%", 'Quotes remain bound and LIKE wildcards escaped.' );
search_check( strpos( $wpdb->query, "Camp'" ) === false, 'Raw query text is never interpolated.' );
search_check( substr_count( $wpdb->query, '%s' ) === 7 && count( $wpdb->values ) === 9, 'Placeholders stay aligned with status, date, search and pagination.' );
MBS_Bookings::get_all();
search_check( strpos( $wpdb->query, 'LIKE' ) === false, 'Unfiltered booking lists are unchanged.' );
MBS_Series::get_all( array( 'search' => 'Frostbite', 'scout_use' => 0 ) );
search_check( strpos( $wpdb->query, 'OR notes LIKE %s)' ) !== false, 'Recurring series search includes descriptions/notes too.' );
search_check( array_slice( $wpdb->values, 0, 7 ) === array_fill( 0, 7, '%Frostbite%' ), 'Series search placeholders aligned.' );
search_check( strpos( $wpdb->query, ') AND scout_use = %d' ) !== false, 'Series Scout filter still applies to notes matches.' );
$rest = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-rest-api.php' );
search_check( strpos( $rest, 'MBS_Bookings::get_all( $args )' ) !== false, 'MCP REST listing shares the corrected search query.' );
echo "OK: $checks booking description search checks passed.\n";
