<?php
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
class WP_Error{function __construct($code,$message){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function get_current_user_id(){return $GLOBALS['user_id'];}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;}
function add_action($hook,$callback){$GLOBALS['shutdown'][]=$callback;}
$wpdb=new class{public $prefix='fixture_';public $busy=false;function prepare($sql,...$args){return $sql;}function get_var($sql){return $this->busy?0:1;}};
$user_id=0;$cache=array();$shutdown=array();
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-submission-guard.php';
$input=array('submission_key'=>'synthetic-request-123456','email'=>'owner@example.invalid','booking_date'=>'2027-01-01','nonce'=>'first');
if(MBS_Submission_Guard::begin($input)!==true)throw new RuntimeException('First submission blocked');
MBS_Submission_Guard::remember(array('ref'=>'MBS-SYNTHETIC','message'=>'Saved'));
$input['nonce']='fresh-nonce';$replay=MBS_Submission_Guard::begin($input);
if(!is_array($replay)||empty($replay['idempotent_replay'])||$replay['ref']!=='MBS-SYNTHETIC')throw new RuntimeException('Retry must replay without creating a booking');
$changed=$input;$changed['booking_date']='2027-01-02';if(!is_wp_error(MBS_Submission_Guard::begin($changed)))throw new RuntimeException('Key reuse for changed input accepted');
$user_id=7;if(MBS_Submission_Guard::begin($input)!==true)throw new RuntimeException('Another identity received a replay');
$wpdb->busy=true;if(!is_wp_error(MBS_Submission_Guard::begin($input)))throw new RuntimeException('Concurrent submission not blocked');
$wpdb->busy=false;if(MBS_Submission_Guard::begin(array('email'=>'cached-form@example.invalid'))!==true)throw new RuntimeException('Cached form compatibility removed');
echo "SUBMISSION_GUARD_OK (replay, changed payload, identity, contention, cached forms)\n";
