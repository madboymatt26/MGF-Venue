<?php
define('ABSPATH',__DIR__);
class WP_Error{function __construct($code,$message){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function update_option($k,$v,$auto=false){$GLOBALS['opts'][$k]=$v;return true;}
function add_option($k,$v,$unused='',$auto=false){if(isset($GLOBALS['opts'][$k]))return false;$GLOBALS['opts'][$k]=$v;return true;}
function wp_json_encode($v){return json_encode($v);}
function current_user_can($cap){return $GLOBALS['admin'];}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-email-templates.php';
$admin=true;$opts=array('mbs_email_template_booking_received'=>array('subject'=>'Received','body'=>"We\\'ve received this. Keep C:\\folder unchanged."));
$before=$opts;$p=MBS_Email_Templates::quote_repair_preview();
if(count($p['before'])!==1||$opts!==$before)throw new RuntimeException('Preview must not mutate settings');
if(!is_wp_error(MBS_Email_Templates::repair_template_quotes('stale'))||$opts!==$before)throw new RuntimeException('Stale repair must do nothing');
if(MBS_Email_Templates::repair_template_quotes($p['fingerprint'])!==true)throw new RuntimeException('Reviewed repair failed');
if($opts['mbs_email_template_booking_received']['body']!=="We've received this. Keep C:\\folder unchanged.")throw new RuntimeException('Repair changed unrelated slashes');
if(!isset($opts['mbs_template_quote_backup_'.substr($p['fingerprint'],0,32)]))throw new RuntimeException('Before-image missing');
$admin=false;if(!is_wp_error(MBS_Email_Templates::repair_template_quotes($p['fingerprint'])))throw new RuntimeException('Non-admin repair allowed');
echo "TEMPLATE_QUOTE_REPAIR_OK (preview, stale check, backup, precise change, permission)\n";
