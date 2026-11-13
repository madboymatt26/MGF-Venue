<?php
/** Execute real staff handler with isolated auth/lock/change-service adapters. */
define('ABSPATH',__DIR__);
class WP_Error {private $message;function __construct($code,$message){$this->message=$message;}function get_error_message(){return $this->message;}}
class HeatingResponse extends RuntimeException {public $ok,$data;function __construct($ok,$data){$this->ok=$ok;$this->data=$data;}}
function wp_send_json_error($data,$status=400){throw new HeatingResponse(false,$data);}
function wp_send_json_success($data){throw new HeatingResponse(true,$data);}
function is_wp_error($value){return $value instanceof WP_Error;}
function check_ajax_referer($action,$field){if(($_POST[$field]??'')!=='valid')wp_send_json_error('Bad nonce',403);}
function current_user_can($cap){return !empty($GLOBALS['manager']);}
function wp_unslash($value){return stripslashes($value);}
function sanitize_text_field($value){return trim(strip_tags($value));}
function wp_json_encode($value){return json_encode($value);}
class MBS_Bookings {static $booking;static function get($ref){return self::$booking&&self::$booking->ref===$ref?clone self::$booking:null;}}
class MBS_Space_Selection {static $depth=0;static function locked($callback){self::$depth++;try{return $callback();}finally{self::$depth--;}}}
class MBS_Booking_Change_Service {
    static $calls=0,$options,$fail=false;
    static function apply($expected,$update,$options){
        if(MBS_Space_Selection::$depth!==1)throw new RuntimeException('Missing occupancy lock');
        self::$calls++;self::$options=$options;
        if(self::$fail)return new WP_Error('fixture','Synthetic commit failure');
        foreach($update as$key=>$value)MBS_Bookings::$booking->$key=$value;
        return array('booking'=>clone MBS_Bookings::$booking);
    }
}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-homeassistant.php';
require __DIR__.'/../wp-plugin/mathlin-booking/admin/class-admin.php';
function assert_heating($value,$message){if(!$value)throw new RuntimeException($message);$GLOBALS['assertions']++;}
function attempt($changes=array()){
    $b=MBS_Bookings::$booking;
    $_POST=array_merge(array('ref'=>'SCOUT','nonce'=>'valid','heating_required'=>'1','expected_version'=>MBS_HomeAssistant::heating_version($b)),$changes);
    try{(new MBS_Admin())->ajax_set_booking_heating();throw new RuntimeException('No response');}catch(HeatingResponse $response){return $response;}
}
$assertions=0;$manager=true;
$original=(object)array('ref'=>'SCOUT','scout_use'=>1,'series_id'=>'','status'=>'paid','amount'=>0,'amount_paid'=>0,'access_sent'=>0,'confirmation_sent'=>1,'custom_fields'=>json_encode(array('answer'=>'preserved')));
MBS_Bookings::$booking=clone$original;
assert_heating(attempt()->ok,'Staff opt-in succeeds');
$fields=json_decode(MBS_Bookings::$booking->custom_fields,true);
assert_heating($fields[MBS_HomeAssistant::HEATING_KEY]===true&&$fields['answer']==='preserved','Preference and existing metadata preserved');
$after=clone MBS_Bookings::$booking;$after->custom_fields=$original->custom_fields;
assert_heating($after==$original,'All status/finance/access/delivery fields preserved');
assert_heating(empty(MBS_Booking_Change_Service::$options['notify'])&&empty(MBS_Booking_Change_Service::$options['reissue'])&&empty(MBS_Booking_Change_Service::$options['financial_change']),'No mail, invoice or financial operation requested');
assert_heating(attempt(array('heating_required'=>'0'))->ok&&!MBS_HomeAssistant::heating_required(MBS_Bookings::$booking),'Explicit opt-out succeeds');
$before=MBS_Booking_Change_Service::$calls;
assert_heating(!attempt(array('expected_version'=>'old'))->ok,'Stale version rejected');
assert_heating(!attempt(array('nonce'=>'bad'))->ok,'Invalid nonce rejected');
assert_heating(!attempt(array('heating_required'=>'true'))->ok,'Ambiguous choice rejected');
$manager=false;assert_heating(!attempt()->ok,'Unprivileged caller rejected');$manager=true;
MBS_Bookings::$booking->series_id='SERIES';assert_heating(!attempt()->ok,'Series occurrence cannot opt in');MBS_Bookings::$booking->series_id='';
MBS_Bookings::$booking->scout_use=0;assert_heating(!attempt()->ok,'External policy cannot be changed here');MBS_Bookings::$booking->scout_use=1;
MBS_Bookings::$booking->status='cancelled';assert_heating(!attempt()->ok,'Inactive booking rejected');MBS_Bookings::$booking->status='paid';
MBS_Bookings::$booking->custom_fields='{broken';assert_heating(!attempt()->ok,'Malformed metadata cannot be overwritten');
assert_heating(MBS_Booking_Change_Service::$calls===$before,'All rejected cases leave change service untouched');
MBS_Bookings::$booking=clone$original;MBS_Booking_Change_Service::$fail=true;
assert_heating(!attempt()->ok&&MBS_Bookings::$booking==$original,'Service failure reported without success or mutation');
assert_heating(MBS_Space_Selection::$depth===0,'Lock released on every response');
echo "PASS {$assertions} Scout heating admin assertions\n";
