<?php
define( 'ABSPATH', __DIR__ );
$options = array();
$scheduled = array();
$cleared = array();
$actions = array();
class WP_Error { public $code; public $message; function __construct($c,$m){$this->code=$c;$this->message=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=null){global $options;$options[$k]=$v;return true;}
function add_action($h,$c,$p=10,$a=1){global $actions;$actions[]=$h;return true;}
function add_filter($h,$c,$p=10,$a=1){return true;}
function wp_get_scheduled_event($h){global $scheduled;return isset($scheduled[$h])?(object)$scheduled[$h]:false;}
function wp_schedule_event($t,$rec,$h){global $scheduled;$scheduled[$h]=array('schedule'=>$rec,'timestamp'=>$t);return true;}
function wp_schedule_single_event($t,$h){global $scheduled;$scheduled[$h]=array('schedule'=>'','timestamp'=>$t);return true;}
function wp_clear_scheduled_hook($h){global $scheduled,$cleared;$cleared[]=$h;unset($scheduled[$h]);return true;}
function wp_next_scheduled($h){global $scheduled;return isset($scheduled[$h])?$scheduled[$h]['timestamp']:false;}
function wp_generate_uuid4(){return 'uuid-'.uniqid();}
function wp_json_encode($v){return json_encode($v);}
function maybe_serialize($v){return is_string($v)?$v:serialize($v);}
function maybe_unserialize($v){$u=@unserialize($v);return ($u===false&&$v!==serialize(false))?$v:$u;}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-payment-features.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-homeassistant.php';
$checks=0;
function expect($condition,$message){global $checks;$checks++;if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function free_booking(){return (object)array('ref'=>'CAMP-1','status'=>'confirmed','amount'=>0,'amount_paid'=>0,'deposit_paid'=>0,'current_invoice_document_id'=>0,'scout_use'=>1,'series_id'=>'','custom_fields'=>'');}

// Venue mode: everything available.
expect(MBS_HomeAssistant::available()===true,'Venue mode keeps Home Assistant available');
expect(MBS_Payment_Features::booking_billing_visible(free_booking())===true,'Venue mode always shows booking billing UI');

// Venue init schedules the worker normally.
( new MBS_HomeAssistant() )->init();
expect(in_array('mbs_ha_outbox',$actions,true),'Venue mode registers the HA worker hook');
expect(isset($scheduled['mbs_ha_outbox']),'Venue mode schedules the HA worker');

// Campsite mode.
$options['mbs_campsite_mode']=1;
expect(MBS_HomeAssistant::available()===false,'Campsite mode disables Home Assistant');
expect(MBS_Payment_Features::booking_billing_visible(free_booking())===false,'Free campsite booking hides billing UI');
expect(MBS_Payment_Features::booking_billing_visible(null)===false,'Campsite mode with no booking hides billing UI');
$paid=free_booking();$paid->amount=25;
expect(MBS_Payment_Features::booking_billing_visible($paid)===true,'Campsite booking with a charge keeps billing UI');
$paid=free_booking();$paid->amount_paid=10;
expect(MBS_Payment_Features::booking_billing_visible($paid)===true,'Campsite booking with recorded payment keeps billing UI');
$paid=free_booking();$paid->deposit_paid=5;
expect(MBS_Payment_Features::booking_billing_visible($paid)===true,'Campsite booking with a deposit keeps billing UI');
$paid=free_booking();$paid->current_invoice_document_id=42;
expect(MBS_Payment_Features::booking_billing_visible($paid)===true,'Campsite booking with an issued document keeps billing UI');

// Campsite init clears a lingering schedule and registers nothing.
$actions=array();$cleared=array();
( new MBS_HomeAssistant() )->init();
expect(!in_array('mbs_ha_outbox',$actions,true),'Campsite mode does not register the HA worker hook');
expect(in_array('mbs_ha_outbox',$cleared,true),'Campsite mode clears a previously scheduled HA worker');
expect(!isset($scheduled['mbs_ha_outbox']),'Campsite mode leaves no HA worker scheduled');

// Every runtime entry point no-ops in campsite mode, even with a webhook configured.
$options['mbs_ha_webhook_url']='http://homeassistant.local:8123/api/webhook/x';
$before=$options;
expect(MBS_HomeAssistant::notify(free_booking())===false,'Campsite confirm notification is not queued');
expect(MBS_HomeAssistant::notify_cancelled(free_booking())===false,'Campsite cancellation notification is not queued');
expect(MBS_HomeAssistant::stage_within_transaction(free_booking(),'pending')===true,'Campsite status change stages no HA intent but proceeds');
expect(!isset($scheduled['mbs_ha_outbox']),'Campsite notifications schedule no worker');
MBS_HomeAssistant::process();
expect(true,'Campsite worker tick returns without touching the database');
$r=MBS_HomeAssistant::retry_failed('CAMP-1','x');
expect(is_wp_error($r) && $r->get_error_code()==='ha_campsite_disabled','Campsite retry of a failed intent is refused');
$keys=array_filter(array_keys($options),static function($k){return strpos($k,'mbs_ha_event_')===0;});
expect(count($keys)===0,'No HA intents were stored in campsite mode');
echo "OK: $checks campsite mode-isolation checks\n";
