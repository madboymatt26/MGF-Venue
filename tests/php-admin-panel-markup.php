<?php
define('ABSPATH',__DIR__);define('MBS_TABLE','mathlin_bookings');
$can=true;$charges=true;
function current_user_can($cap){global$can;return$can;}
function get_option($key,$default=null){return$default;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function admin_url($v){return'https://example.invalid/wp-admin/'.$v;}
function current_time($v){return'2026-10-28 10:00:00';}
function wp_nonce_field($v){echo'<input name="_wpnonce" value="synthetic">';}
function checked($a,$b=true,$echo=true){$s=$a==$b?'checked="checked"':'';if($echo)echo$s;return$s;}
function selected($a,$b=true,$echo=true){$s=$a==$b?'selected="selected"':'';if($echo)echo$s;return$s;}
class Panel_DB{public$prefix='wp_';public$last_error='';function prepare($sql,...$args){return$sql;}function get_results($sql){return array();}}
$wpdb=new Panel_DB();
class MBS_Payment_Features{static function enabled($feature){global$charges;return$feature==='additional_charges'&&$charges;}}
class MBS_Email_Queue{static function get_stats(){return array('available'=>true,'failed'=>0);}}
class MBS_Integration_Health{static function job_status(){return array();}}
class MBS_Google_Calendar{static function status(){return array('connected'=>true,'last_result'=>'0 event changes; 8 need review; 0 queued.','last_success'=>null);}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-attention.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-extras-catalogue.php';
$checks=0;function panel_assert($ok,$why){global$checks;$checks++;if(!$ok)throw new RuntimeException($why);}
ob_start();MBS_Attention::render();$attention=ob_get_clean();
foreach(array('mbs-disclosure mbs-attention','summary class="nms-card-header"','class="mbs-card-body"','Outstanding booking actions','scope="col"','8 references need a decision','sync_view=review')as$s)panel_assert(strpos($attention,$s)!==false,'Attention markup: '.$s);
panel_assert(strpos($attention,'style="margin:16px 0;padding:16px"')===false,'No double card padding');
ob_start();(new MBS_Extras_Catalogue())->render_settings();$extras=ob_get_clean();
foreach(array('mbs-disclosure mbs-extras-settings','summary class="nms-card-header"','class="mbs-card-body"','aria-label="Reusable extras"','mbs-inline-disclosure','name="operation" value="enable"','name="operation" value="save"','name="revision" value="0"')as$s)panel_assert(strpos($extras,$s)!==false,'Extras markup: '.$s);
panel_assert(!preg_match('/name="enabled"[^>]+checked/',$extras),'Catalogue stays disabled');
$dom=new DOMDocument();$prior=libxml_use_internal_errors(true);$dom->loadHTML('<html><body>'.$extras.'</body></html>',LIBXML_NONET);$xp=new DOMXPath($dom);
panel_assert($xp->query('//form')->length===2,'Enable and item forms retained');
panel_assert($xp->query('//form//form')->length===0,'No nested forms');
panel_assert($xp->query('//details[contains(@class,"mbs-extras-settings")]/div[contains(@class,"mbs-card-body")]')->length===1,'Single extras body wrapper');
libxml_clear_errors();libxml_use_internal_errors($prior);
$charges=false;ob_start();(new MBS_Extras_Catalogue())->render_settings();panel_assert(ob_get_clean()==='','Disabled charges do not render catalogue');
$can=false;ob_start();MBS_Attention::render();panel_assert(ob_get_clean()==='','Attention permission guard retained');
echo "ADMIN_PANEL_MARKUP_OK: {$checks} assertions\n";
