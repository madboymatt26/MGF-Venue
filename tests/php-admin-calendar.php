<?php
// Isolated render and query contract: no live database, Google or customer mail.
define('ABSPATH', __DIR__);
define('MBS_TABLE', 'bookings');
function absint($v) { return abs((int)$v); }
function wp_date($format) { return gmdate($format, strtotime('2026-10-21 UTC')); }
function sanitize_text_field($v) { return strip_tags($v); }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function admin_url($v) { return '/wp-admin/'.$v; }
function add_query_arg($args, $url = '/wp-admin/admin.php') { return $url.'?'.http_build_query($args); }
class MBS_Admin { static function brand_mark() { return ''; } }
class MBS_Blocked_Dates { static function get_for_month($year, $month) { return array('2026-11-04'=>array(array('space'=>'Paddock','reason'=>'Maintenance'))); } }
class MBS_Payment_Features { static $paid = false; static function enabled() { return self::$paid; } }
class Calendar_DB {
    public $prefix = 'wp_';
    public $sql;
    function prepare($sql, ...$args) { $this->sql = $sql; return vsprintf(str_replace('%s', "'%s'", $sql), $args); }
    function get_results($sql) {
        if (strpos($sql, "booking_date <= '2026-11-30'") === false || strpos($sql, ">= '2026-11-01'") === false) throw new RuntimeException('Month-overlap query bounds');
        if (strpos($sql, 'COALESCE(NULLIF(booking_date_end') === false) throw new RuntimeException('End date and single-day fallback required');
        if (strpos($sql, "status NOT IN ('cancelled', 'archived')") === false) throw new RuntimeException('Inactive bookings excluded');
        return array((object)array('ref'=>'SPAN-1','booking_date'=>'2026-10-30','booking_date_end'=>'2026-11-02','name'=>'Test <contact>','organisation'=>'Test group','space'=>'Whole Park','all_day'=>1,'status'=>'confirmed','start_time'=>'00:00:00','end_time'=>'23:59:00','attendees'=>50,'amount'=>25,'purpose'=>'Safe <purpose>'));
    }
}
$wpdb = new Calendar_DB();
function render_calendar($selected) {
    $_GET = array('cal_year'=>2026,'cal_month'=>11,'cal_date'=>$selected);
    ob_start(); include dirname(__DIR__).'/wp-plugin/mathlin-booking/admin/views/calendar.php'; return ob_get_clean();
}
$html = render_calendar('2026-11-01');
foreach (array('SPAN-1','All day','Test &lt;contact&gt;','Safe &lt;purpose&gt;','2026-10-30 – 2026-11-02','aria-current="date"','1 booking overlapping this month') as $expected) {
    if (strpos($html, $expected) === false) throw new RuntimeException('Missing '.$expected);
}
if (strpos($html, '&pound;') !== false) throw new RuntimeException('Free venue must not show payment amount');
if (substr_count($html, 'class="nms-cal-badge"') !== 2) throw new RuntimeException('Stay appears on both occupied November days only');
if (strpos(render_calendar('2026-11-03'), 'No bookings on this day.') === false) throw new RuntimeException('No spill beyond checkout day');
if (strpos(render_calendar('not-a-date'), 'Sunday 1 November 2026') === false) throw new RuntimeException('Invalid selection must fall back safely');
if (strpos(render_calendar('2026-11-04'), 'Maintenance') === false) throw new RuntimeException('Partial block details retained');
MBS_Payment_Features::$paid = true;
if (strpos(render_calendar('2026-11-02'), '&pound;25.00') === false) throw new RuntimeException('Paid venue retains amount');
echo "ADMIN_CALENDAR_OK: overlap, multi-day, selection, escaping, blocks and payment switch\n";
