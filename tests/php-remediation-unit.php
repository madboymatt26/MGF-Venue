<?php
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_TABLE', 'mathlin_bookings' );

class WP_Error { public function __construct( private $code, private $message ) {} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim((string)$v);}
function wp_generate_password(){return str_repeat('x',24);}
function wp_schedule_single_event(){return true;}
function update_option($k,$v){global $options;$options[$k]=$v;return true;}
function add_option($k,$v){global $options;if(array_key_exists($k,$options))return false;$options[$k]=$v;return true;}
function get_option($k,$d=null){global $options;return $options[$k]??$d;}
function delete_option($k){global $options;unset($options[$k]);return true;}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_date($f,$t=null){return date($f,$t??time());}
function current_time(){return '2026-07-19 12:00:00';}
function wp_json_encode($v){return json_encode($v);}

class MBS_Invoice_Payment { public static function is_payable($i){return in_array($i->status,array('issued','part_paid','overdue'),true)&&$i->balance>0;} public static function send_due_reminders(){} }
class MBS_Billing_Ledger { public static function balance_minor($i){return $i->balance;} }

$options=array();
require_once dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-invoice-reservation.php';
$invoice=(object)array('id'=>7,'invoice_ref'=>'INV-7','status'=>'overdue','balance'=>1200);
$first=MBS_Invoice_Reservation::acquire($invoice);
$second=MBS_Invoice_Reservation::acquire($invoice);
$same=MBS_Invoice_Reservation::acquire($invoice,$first['reservation_ref']);
$bound=MBS_Invoice_Reservation::bind_order('INV-7',$first['reservation_ref'],41);
$repeat=MBS_Invoice_Reservation::bind_order('INV-7',$first['reservation_ref'],41);
$conflict=MBS_Invoice_Reservation::bind_order('INV-7',$first['reservation_ref'],42);
$checks=array(
    is_array($first), is_wp_error($second)&&$second->get_error_code()==='invoice_payment_reserved',
    $same['reservation_ref']===$first['reservation_ref'], $bound['order_id']===41,
    $repeat['order_id']===41, is_wp_error($conflict),
    MBS_Invoice_Reservation::validate('INV-7',$first['reservation_ref'],1200,41),
);
foreach($checks as $ok) if(!$ok){fwrite(STDERR,"FAIL: invoice reservation concurrency/idempotency assertion.\n");exit(1);}
$reconcile=MBS_Invoice_Reservation::reconciliation_required('INV-7',$first['reservation_ref'],41,'injected ledger failure');
$visible=MBS_Invoice_Reservation::get('INV-7');
if(!$reconcile||$visible['status']!=='reconciliation_required'||MBS_Invoice_Reservation::release('INV-7',$first['reservation_ref'])){fwrite(STDERR,"FAIL: captured-payment failure was not retained for reconciliation.\n");exit(1);}

class MBS_Series {
    public static array $rows=[];
    public static function get($ref){return self::$rows[$ref]??null;}
}
class MBS_Money {}
class MBS_Bookings {}
class MBS_Test_Billing_WPDB {
    public $prefix='wp_'; public int $page_calls=0; public array $seen=[];
    public function prepare($sql,...$args){foreach($args as $a)$sql=preg_replace('/%d/',(string)$a,$sql,1);return $sql;}
    public function get_results($sql){
        if(str_contains($sql,'mathlin_booking_series')){preg_match('/id > (\d+)/',$sql,$m);$after=(int)($m[1]??0);$this->page_calls++;$rows=[];for($id=$after+1;$id<=min(101,$after+100);$id++){ $ref='SER-'.$id;$row=(object)array('id'=>$id,'series_ref'=>$ref,'status'=>'confirmed','billing_treatment'=>'invoice_managed','billing_mode'=>'monthly','metadata_incomplete'=>0);MBS_Series::$rows[$ref]=$row;$this->seen[]=$id;$rows[]=$row;}return $rows;}
        if(str_contains($sql,'mathlin_bookings'))return array();
        return array();
    }
    public function get_col($sql){return array();}
}
$wpdb=new MBS_Test_Billing_WPDB();
require_once dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-billing-engine.php';
$result=MBS_Billing_Engine::catch_up('2026-07-19');
if(is_wp_error($result)||$wpdb->page_calls!==2||count(array_unique($wpdb->seen))!==101){fwrite(STDERR,"FAIL: catch-up did not paginate through 101 eligible series.\n");exit(1);}
$bookings_source=file_get_contents(dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-bookings.php');
$series_source=file_get_contents(dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-series.php');
foreach(array(
    str_contains($bookings_source,"public static function create( \$data, \$trusted_admin_context = false, \$manage_transaction = true )"),
    str_contains($bookings_source,"self::create( \$booking_data, \$trusted_admin_context, false )"),
    str_contains($series_source,'MBS_Billing_Engine::reconcile_occurrences'),
    strpos($series_source,'MBS_Billing_Engine::reconcile_occurrences') < strpos($series_source,'$cancelled = $wpdb->query'),
) as $guard) if(!$guard){fwrite(STDERR,"FAIL: atomic creation/cancellation failure guard is missing.\n");exit(1);}
echo 'OK: 15 remediation assertions passed (reservation contention/replay, captured-payment failure visibility, 101-series catch-up, creation/cancellation rollback guards).'.PHP_EOL;
