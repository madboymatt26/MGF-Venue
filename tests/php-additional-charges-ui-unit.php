<?php
define('ABSPATH',__DIR__);define('MBS_PLUGIN_DIR',__DIR__.'/../wp-plugin/mathlin-booking/');define('MBS_INVOICE_TABLE','invoices');define('MBS_INVOICE_ITEM_TABLE','items');define('MBS_PAYMENT_RESERVATION_TABLE','reservations');define('ARRAY_A','ARRAY_A');
function current_user_can($cap){return $GLOBALS['manager'];}
class WP_Error {}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_unslash($s){return $s;}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));}
function absint($n){return abs((int)$n);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function admin_url($path){return 'https://example.test/wp-admin/'.$path;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_json_encode($v){return json_encode($v);}
function selected($a,$b,$echo=true){$s=$a===$b?'selected':'';if($echo)echo $s;return $s;}
function current_time($format){return '2026-10-15 22:00:00';}
class MBS_Admin {public static function brand_mark(){return '<span>MGF Venue</span>';}}
class MBS_Bookings {public static function get($ref){return $ref==='MBS-TEST'?(object)array('ref'=>$ref,'name'=>'Synthetic','purpose'=>'Synthetic hire','booking_date'=>'2026-10-14','space'=>'Hall','status'=>'archived'):null;}}
class MBS_Series {public static function get($ref){return $ref==='SER-SCOUT'?(object)array('scout_use'=>1,'contact_name'=>'Synthetic Scout','purpose'=>'Training','space'=>'Hall'):null;}}
class MBS_Billing_Ledger {
    public static function get_items($id){return array((object)array('description'=>'Cleaning <unsafe>','booking_ref'=>null,'line_total_minor'=>2500,'pricing_snapshot_json'=>'{"related_ref":"MBS-TEST","category":"cleaning"}'));}
    public static function balance_minor($invoice){return $invoice->total_minor-$invoice->paid_minor-$invoice->credited_minor;}
}
class MBS_Invoice_Payment {public static function is_payable($i){return $i->status==='issued'&&MBS_Billing_Ledger::balance_minor($i)>0;}}
class ChargeUiDb {public $prefix='fake_';public $calls=0;public function prepare($sql,...$args){return $sql;}public function esc_like($s){return addcslashes($s,'_%');}public function get_results($sql,$mode=null){$this->calls++;return strpos($sql,'mathlin_email_queue')!==false?array():array($GLOBALS['invoice']);}}
$wpdb=new ChargeUiDb();$GLOBALS['manager']=true;
$GLOBALS['invoice']=(object)array('id'=>1,'invoice_ref'=>'INV-TEST','billing_mode'=>'additional_charge','status'=>'issued','total_minor'=>2500,'paid_minor'=>0,'credited_minor'=>0,'contact_name'=>'Synthetic','contact_organisation'=>'Test','due_at'=>'2026-10-29 23:59:59');
require MBS_PLUGIN_DIR.'includes/class-money.php';
require MBS_PLUGIN_DIR.'includes/class-additional-charges.php';
$checks=0;function ui_assert($ok,$message){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$_GET=array();ob_start();(new MBS_Additional_Charges())->render();$html=ob_get_clean();
ui_assert(strpos($html,'nms-bookings-table')!==false,'Reuses the established bookings table');
ui_assert(strpos($html,'name="amount"')===false,'List does not contain long create forms');
ui_assert(strpos($html,'Original booking')!==false,'Explicit original-booking column');
ui_assert(strpos(html_entity_decode($html),'page=mathlin-booking&action=view&ref=MBS-TEST')!==false,'Archived original booking has a real detail link');
ui_assert(strpos($html,'invoice_ref=INV-TEST')!==false,'Invoice opens a dedicated charge detail');
ui_assert(strpos($html,'Cleaning &lt;unsafe&gt;')!==false,'List description escaped');
ui_assert(strpos($html,'view=create')!==false,'Creation is a separate explicit view');
$context=MBS_Additional_Charges::related_context('SER-SCOUT');
ui_assert(strpos($context['url'],'mathlin-scout-nights')!==false,'Scout series links to the Scout detail view');
$context=MBS_Additional_Charges::related_context('MISSING');ui_assert($context['url']==='','Deleted booking does not create a fake link');
ob_start();MBS_Additional_Charges::render_related('MBS-TEST');$panel=ob_get_clean();
ui_assert(strpos($panel,'Add additional charge')!==false&&strpos($panel,'INV-TEST')!==false,'Booking panel shows linked invoices and contextual add action');
$GLOBALS['manager']=false;$before=$wpdb->calls;ob_start();(new MBS_Additional_Charges())->render();$blocked=ob_get_clean();
ui_assert($blocked===''&&$wpdb->calls===$before,'No charge list read without booking-manager permission');
echo "Additional charge UI tests passed ($checks checks).\n";
