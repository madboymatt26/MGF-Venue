<?php
define('ABSPATH',__DIR__);$can=true;$camp=false;$paid=true;
function current_user_can($cap){global$can;return$can;}
function get_option($k,$d=0){return$d;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return esc_html($v);}
function admin_url($path){return'https://example.invalid/'.$path;}
function wp_die(){throw new RuntimeException('forbidden');}
class MBS_Campsite_Policy{static function enabled(){global$camp;return$camp;}}
class MBS_Payment_Features{static function enabled($f){global$paid;return$paid;}}
class MBS_Space_Selection{static function enabled(){global$camp;return$camp;}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-operator-guide.php';
$guide=new MBS_Operator_Guide();ob_start();$guide->render();$venue=ob_get_clean();
if(strpos($venue,'Invoices, payments and extras')===false||strpos($venue,'read-only')===false)throw new RuntimeException('Venue handover missing');
$camp=true;$paid=false;ob_start();$guide->render();$free=ob_get_clean();
if(strpos($free,'Invoices, payments and extras')!==false||strpos($free,'No payment check is required')===false||strpos($free,'Whole Park')===false)throw new RuntimeException('Free campsite handover must respect payment gates');
$can=false;ob_start();try{$guide->render();throw new RuntimeException('Unauthorised guide access');}catch(RuntimeException$e){if($e->getMessage()!=='forbidden')throw$e;}finally{ob_end_clean();}
echo "OPERATOR_GUIDE_OK: paid/free profiles and permission guard\n";
