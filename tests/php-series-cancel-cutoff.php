<?php
/** Domain tests: no WordPress installation, database or outbound services required. */
define('ABSPATH', __DIR__);
define('MBS_TABLE', 'bookings');
define('MBS_SERIES_TABLE', 'series');
class WP_Error {
    public $code, $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return (string)$value; }
function wp_date($format) { return '2026-10-12'; }
function current_time($format) { return '2026-10-12 12:00:00'; }
function wp_list_pluck($rows, $key) { return array_map(fn($row) => $row->$key, $rows); }
class MBS_Billing_Engine {
    public static $refs = array(), $fail = false;
    public static function reconcile_occurrences($refs, $manage_transaction) {
        if ($manage_transaction) throw new RuntimeException('Reconciliation must share the cancellation transaction.');
        self::$refs = $refs;
        return self::$fail ? new WP_Error('test', 'Injected reconciliation failure') : array();
    }
}
class MBS_Email {
    public static $sent = array();
    public static function notify_series_changed($series, $rows) { self::$sent[] = 'changed'; }
    public static function notify_series_cancelled($series, $rows) { self::$sent[] = 'cancelled'; }
}
class MBS_HomeAssistant {
    public static $refs = array();
    public static function notify_cancelled($booking) { self::$refs[] = $booking->ref; }
}
class MBS_Audit_Log {
    public static $events = array();
    public static function log(...$args) { self::$events[] = $args; }
}
class CutoffDB {
    public $prefix = '', $last_error = '', $series, $rows, $snapshot;
    public $fail_remaining = false, $fail_update = false, $fail_version = false, $fail_commit = false;
    public function __construct() {
        $this->series = (object)array('series_ref'=>'TEST', 'status'=>'confirmed', 'version'=>1);
        $this->rows = array();
        foreach (array('2026-10-01'=>'paid', '2026-10-28'=>'paid', '2026-11-01'=>'confirmed', '2026-11-04'=>'deposit_paid', '2026-12-02'=>'pending', '2026-12-09'=>'cancelled', '2026-12-16'=>'archived') as $date=>$status) {
            $this->rows[] = (object)array('ref'=>$date, 'booking_date'=>$date, 'status'=>$status, 'access_sent'=>1, 'ha_notified'=>1);
        }
    }
    public function prepare($sql, ...$args) {
        return array($sql, count($args)===1 && is_array($args[0]) ? $args[0] : $args);
    }
    public function get_row($query) { return clone $this->series; }
    private function eligible($row, $sql, $args) {
        if (strpos($sql, "status NOT IN")!==false) return !in_array($row->status, array('cancelled','archived'), true);
        if (strpos($sql, '(status IN')!==false) return in_array($row->status, array('pending','confirmed'), true) || ($row->booking_date >= $args[1] && in_array($row->status, array('paid','deposit_paid'), true));
        return $row->booking_date >= $args[1] && in_array($row->status, array('pending','confirmed','deposit_paid','paid'), true);
    }
    public function get_results($query) {
        [$sql,$args] = $query;
        $this->last_error = '';
        if ($this->fail_remaining && strpos($sql, 'SELECT ref ')===0) { $this->last_error = 'Injected failure'; return null; }
        return array_values(array_map(fn($row)=>clone $row, array_filter($this->rows, fn($row)=>$this->eligible($row,$sql,$args))));
    }
    public function query($query) {
        if (is_string($query)) {
            if ($query==='START TRANSACTION') $this->snapshot = serialize(array($this->series,$this->rows));
            if ($query==='ROLLBACK') [$this->series,$this->rows] = unserialize($this->snapshot);
            if ($query==='COMMIT' && $this->fail_commit) return false;
            return 1;
        }
        [$sql,$args] = $query;
        if (strpos($sql, 'UPDATE bookings')===0) {
            if ($this->fail_update) return false;
            $count = 0;
            foreach ($this->rows as $row) if ($this->eligible($row,$sql,$args)) { $row->status='cancelled'; $row->access_sent=0; $count++; }
            return $count;
        }
        if ($this->fail_version) return 0;
        $this->series->status = $args[0]; $this->series->version++;
        return 1;
    }
}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-series.php';
$checks = 0;
function cutoff_assert($condition, $message) {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function cutoff_reset() {
    global $wpdb; $wpdb = new CutoffDB();
    MBS_Billing_Engine::$refs = MBS_Email::$sent = MBS_HomeAssistant::$refs = MBS_Audit_Log::$events = array();
    MBS_Billing_Engine::$fail = false;
}
cutoff_reset();
$result = MBS_Series::cancel('TEST','from_date','confirmed',1,true,'2026-11-01');
cutoff_assert(!is_wp_error($result) && $result['cancelled']===3, 'Inclusive cutoff cancels only actionable later occurrences.');
cutoff_assert(MBS_Billing_Engine::$refs===array('2026-11-01','2026-11-04','2026-12-02'), 'Only selected occurrences are reconciled.');
cutoff_assert($wpdb->rows[0]->status==='paid' && $wpdb->rows[1]->status==='paid' && $wpdb->rows[1]->access_sent===1, 'Earlier sessions, payment state and access flag are retained.');
cutoff_assert($wpdb->rows[2]->access_sent===0 && $wpdb->rows[6]->status==='archived', 'Cancelled sessions lose access eligibility; archived history is unchanged.');
cutoff_assert($result['series']->status==='confirmed' && $result['series']->version===2, 'Retained future sessions keep series active with a new version.');
cutoff_assert(MBS_Email::$sent===array('changed') && count(MBS_HomeAssistant::$refs)===3, 'Only affected sessions notify HA; hirer gets updated schedule.');
cutoff_assert(strpos(MBS_Audit_Log::$events[0][2], '2026-11-01 inclusive')!==false, 'Audit records cutoff.');
$again = MBS_Series::cancel('TEST','from_date','confirmed',2,true,'2026-11-01');
cutoff_assert($again['no_op'] && $wpdb->series->version===2 && count(MBS_Email::$sent)===1, 'Repeat cancellation is a no-op without notification replay.');
foreach (array('','2026-02-30','2026-10-11','2026-11-1','invalid') as $date) {
    cutoff_reset(); $result=MBS_Series::cancel('TEST','from_date','confirmed',1,false,$date);
    cutoff_assert(is_wp_error($result) && $result->code==='invalid_cancel_date' && $wpdb->snapshot===null, 'Reject invalid/past date before transaction: '.$date);
}
cutoff_reset();
cutoff_assert(is_wp_error(MBS_Series::cancel('TEST','typo','confirmed',1)), 'Unknown scope must not cancel entire series.');
cutoff_assert(is_wp_error(MBS_Series::cancel('TEST','future','confirmed',1,false,'2026-11-01')), 'Cutoff cannot be silently ignored for other scopes.');
cutoff_assert(is_wp_error(MBS_Series::cancel('TEST','from_date','confirmed',0,false,'2026-11-01')), 'Stale version rejected.');
foreach (array('reconcile','fail_remaining','fail_update','fail_version','fail_commit') as $failure) {
    cutoff_reset(); $before=serialize(array($wpdb->series,$wpdb->rows));
    if ($failure==='reconcile') MBS_Billing_Engine::$fail=true; else $wpdb->$failure=true;
    $result=MBS_Series::cancel('TEST','from_date','confirmed',1,true,'2026-11-01');
    cutoff_assert(is_wp_error($result) && serialize(array($wpdb->series,$wpdb->rows))===$before, 'Failure rolls back booking and series changes: '.$failure);
    cutoff_assert(!MBS_Email::$sent && !MBS_Audit_Log::$events && !MBS_HomeAssistant::$refs, 'No external notifications before successful commit: '.$failure);
}
cutoff_reset(); $wpdb->series->status='paused';
$result=MBS_Series::cancel('TEST','from_date','paused',1,false,'2026-11-01');
cutoff_assert($result['series']->status==='paused' && !MBS_Email::$sent, 'Paused series stays paused and email remains opt-in.');
foreach (array('future','all','from_date') as $scope) {
    cutoff_reset(); $result=MBS_Series::cancel('TEST',$scope,'confirmed',1,true,$scope==='from_date'?'2026-10-12':'');
    cutoff_assert($result['cancelled']===4 && $wpdb->rows[0]->status==='paid', 'Existing scopes/today cutoff retain historical paid sessions: '.$scope);
    cutoff_assert($result['series']->status===($scope==='all'?'cancelled':'cancelled_future') && MBS_Email::$sent===array('cancelled'), 'Fully cancelled future schedule gets cancellation notification: '.$scope);
}
echo "OK: {$checks} cancellation cutoff assertions passed.\n";
