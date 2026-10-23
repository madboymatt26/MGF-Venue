<?php
define('ABSPATH',__DIR__);define('MBS_TABLE','bookings');
class WP_Error{function __construct($code,$message){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($key,$default=false){return $GLOBALS['settings'][$key]??$default;}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($v){return trim(strip_tags($v));}
class MBS_Bookings{static function get_spaces(){return array('Hall'=>array('parent'=>'Whole'),'Whole'=>array('parent'=>null));}}
class MBS_Space_Selection{static function members($value){return explode(' + ',$value);}}
class SettingsDB{public $prefix='test_';public $last_error='';public $rows=array();function get_results($sql){return $this->rows;}}
$wpdb=new SettingsDB();$settings=array('mbs_campsite_mode'=>0,'mbs_payments_enabled'=>1);
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-settings-guard.php';
$checks=0;function check($v,$message){global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
$payload=array('spaces'=>array(array('name'=>'Hall','parent'=>'Whole','rate_hourly'=>15),array('name'=>'Whole','parent'=>'')));
$hash=MBS_Settings_Guard::fingerprint();
check(MBS_Settings_Guard::preflight(array('expected_settings_hash'=>$hash))===true,'unchanged settings accepted');
$settings['mbs_campsite_mode']=1;check(is_wp_error(MBS_Settings_Guard::preflight(array('expected_settings_hash'=>$hash))),'concurrent mode change detected');
check(MBS_Settings_Guard::preflight($payload)===true,'valid parent mapping');
$bad=$payload;$bad['spaces'][1]['parent']='Hall';check(is_wp_error(MBS_Settings_Guard::preflight($bad)),'cycle rejected before write');
$bad=$payload;$bad['spaces'][1]['name']='Hall';check(is_wp_error(MBS_Settings_Guard::preflight($bad)),'duplicate names rejected');
$bad=$payload;$bad['spaces'][0]['rate_hourly']='not-a-number';check(is_wp_error(MBS_Settings_Guard::preflight($bad)),'invalid price rejected');
$wpdb->rows=array((object)array('space'=>'Hall'));$bad=$payload;array_shift($bad['spaces']);check(is_wp_error(MBS_Settings_Guard::preflight($bad)),'active allocation cannot be removed');
$wpdb->last_error='synthetic read failure';check(is_wp_error(MBS_Settings_Guard::preflight($payload)),'failed read cannot authorize reconfiguration');
echo 'SETTINGS_GUARD_OK ('.$checks.' checks)'.PHP_EOL;
