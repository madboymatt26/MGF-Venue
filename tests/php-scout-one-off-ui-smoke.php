<?php
// Dependency-free query and template regression tests; no live data or mail.
define( 'ABSPATH', __DIR__ );
define( 'MBS_TABLE', 'bookings' );
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp === null ? strtotime( '2026-10-09' ) : $timestamp ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function selected( $value, $option ) { if ( $value === $option ) echo 'selected="selected"'; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
class Scout_One_Off_DB {
    public $prefix = 'test_';
    public $query;
    public $values;
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function prepare( $query, $values ) { $this->query = $query; $this->values = $values; return $query; }
    public function get_results( $query ) { return array(); }
}
$wpdb = new Scout_One_Off_DB();
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-bookings.php';
$checks = 0;
function one_off_assert( $condition, $message ) {
    global $checks;
    $checks++;
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
MBS_Bookings::get_all( array( 'scout_only' => true, 'one_off_only' => true, 'date_scope' => 'current', 'exclude_archived' => false, 'search' => "Test'_%", 'limit' => 51, 'offset' => 50 ) );
one_off_assert( strpos( $wpdb->query, 'scout_use = 1' ) !== false, 'External hires excluded in SQL.' );
one_off_assert( strpos( $wpdb->query, "(series_id IS NULL OR series_id = '')" ) !== false, 'Recurring occurrences excluded before pagination.' );
one_off_assert( strpos( $wpdb->query, "COALESCE(NULLIF(booking_date_end, ''), booking_date) >= %s" ) !== false, 'Current multi-day bookings remain visible.' );
one_off_assert( $wpdb->values[0] === '2026-10-09', 'Date boundary uses venue date.' );
one_off_assert( strpos( $wpdb->query, "Test'" ) === false && $wpdb->values[1] === "%Test'\\_\\%%", 'Search is escaped and parameterised.' );
one_off_assert( array_slice( $wpdb->values, -2 ) === array( 51, 50 ), 'Limit and offset applied in SQL.' );
MBS_Bookings::get_all( array( 'date_scope' => 'ended', 'status' => 'archived', 'one_off_only' => true ) );
one_off_assert( strpos( $wpdb->query, ') < %s' ) !== false && $wpdb->values[0] === 'archived', 'Past and archived filters supported.' );
MBS_Bookings::get_all();
one_off_assert( strpos( $wpdb->query, 'series_id IS NULL' ) === false && strpos( $wpdb->query, 'COALESCE' ) === false, 'Other booking lists unchanged by default.' );

$search = '<script>search</script>';
$status = '';
$scope = 'all';
$one_off_page = 2;
$one_off_has_next = true;
$one_off_bookings = array( (object) array(
    'ref' => 'MBS-TEST', 'purpose' => '<script>course</script>', 'name' => 'Test Contact', 'organisation' => 'Test Scouts',
    'booking_date' => '2026-10-08', 'booking_date_end' => '2026-10-17', 'all_day' => false,
    'start_time' => '09:15:00', 'end_time' => '15:30:00', 'space' => 'Whole Centre', 'kitchen' => true, 'status' => 'paid',
) );
function render_one_off_test() {
    global $search, $status, $scope, $one_off_page, $one_off_has_next, $one_off_bookings;
    ob_start();
    include dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/admin/views/scout-one-off.php';
    return ob_get_clean();
}
$html = render_one_off_test();
one_off_assert( strpos( $html, 'ref=MBS-TEST' ) !== false, 'Reference opens existing booking details.' );
one_off_assert( strpos( $html, 'Scout use · no charge' ) !== false, 'No-charge label rendered.' );
one_off_assert( strpos( $html, '<script>' ) === false && strpos( $html, '&lt;script&gt;' ) !== false, 'Stored and query text escaped.' );
one_off_assert( strpos( $html, '17 Oct 2026' ) !== false && strpos( $html, '09:15–15:30' ) !== false, 'Multi-day dates and timed slot rendered.' );
one_off_assert( strpos( $html, 'tab=one-off' ) !== false && strpos( $html, 'paged=3' ) !== false && strpos( $html, 'scope=all' ) !== false, 'Pagination retains tab and filters.' );
$one_off_bookings[0]->all_day = true;
one_off_assert( strpos( render_one_off_test(), 'All day' ) !== false, 'All-day reservations rendered.' );
$one_off_bookings = array();
$one_off_page = 1;
$one_off_has_next = false;
$html = render_one_off_test();
one_off_assert( strpos( $html, 'No one-off Scout bookings match' ) !== false && strpos( $html, '>Next<' ) === false, 'Empty state and final page rendered.' );

$view = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/admin/views/scout-nights.php' );
$single = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/admin/views/single.php' );
one_off_assert( strpos( $view, 'Recurring bookings' ) !== false && strpos( $view, 'One-off bookings' ) !== false && strpos( $view, 'aria-current="page"' ) !== false, 'Both accessible tabs exist.' );
one_off_assert( strpos( $single, 'One-off Scout bookings' ) !== false, 'One-off booking detail has return navigation.' );
echo "OK: {$checks} one-off Scout UI assertions passed.\n";
