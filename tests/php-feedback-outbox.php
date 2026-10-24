<?php
define('ABSPATH',__DIR__);define('MBS_TABLE','bookings');$options=array('mbs_feedback_enabled'=>1,'mbs_feedback_review_url'=>'https://example.test/review');$queued=array();
class WP_Error{public function __construct($code,$message){}}
function is_wp_error($value){return$value instanceof WP_Error;}
function get_option($key,$default=false){return$GLOBALS['options'][$key]??$default;}
function add_option($key,$value,$unused='',$autoload=false){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function wp_timezone(){return new DateTimeZone('Europe/London');}
class MBS_Access_Details{public static function source_hash($b){return hash('sha256',$b->ref.'|'.$b->email);}}
class MBS_Email_Queue{public static $fail=false;public static function compute_payload_hash(...$args){return hash('sha256',serialize($args));}public static function enqueue($to,$subject,$body,$headers,$key,$hash,$attachment,$context){if(self::$fail)return new WP_Error('failed','fixture');$GLOBALS['queued'][$context['reference_id']]=$key;return true;}}
class FeedbackDB{public $prefix='fixture_';public $updates=0;public $last_sql='';public function prepare($sql,...$args){$this->last_sql=$sql;return$sql;}public function get_results($sql){$out=array();for($id=1;$id<=51;$id++){if(isset($GLOBALS['queued'][$id]))continue;$out[]=(object)array('id'=>$id,'ref'=>'F-'.$id,'email'=>'test@example.test');if(count($out)===50)break;}return$out;}public function update(...$args){$this->updates++;return 1;}}
$wpdb=new FeedbackDB();require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-feedback.php';
MBS_Email_Queue::$fail=true;(new MBS_Feedback())->send_feedback_requests();if($queued||$wpdb->updates)throw new RuntimeException('Failure incorrectly marks queued/sent');
MBS_Email_Queue::$fail=false;(new MBS_Feedback())->send_feedback_requests();if(count($queued)!==50||$wpdb->updates)throw new RuntimeException('Premature sent bookkeeping or incorrect batch');
(new MBS_Feedback())->send_feedback_requests();if(count($queued)!==51)throw new RuntimeException('51st feedback item starved');
if(strpos($wpdb->last_sql,'BETWEEN %s AND %s')===false||strpos($wpdb->last_sql,'NOT EXISTS')===false)throw new RuntimeException('Catchup window or durable exclusion missing');
echo "PASS feedback enqueue failure, acceptance boundary and 51-record catchup\n";
