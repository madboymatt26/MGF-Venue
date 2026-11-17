<?php
/** Exercise the real HTTP handler with side-effect-counting WordPress/Woo doubles. */
define('ABSPATH', __DIR__);
define('MBS_PLUGIN_DIR', dirname(__DIR__).'/wp-plugin/mathlin-booking/');
class IntentStop extends RuntimeException { public $status; function __construct($message,$status){parent::__construct($message);$this->status=$status;} }
class WP_Error { private $code; function __construct($code){$this->code=$code;} function get_error_code(){return $this->code;} function get_error_message(){return 'reservation error';} }
class WooCommerce {}
class MBS_Billing_Ledger { static function get_invoice($ref){return $ref==='INT-PAY'?$GLOBALS['invoice']:null;} static function balance_minor($i){return $i->balance;} }
class MBS_Invoice_Payment { static function verify_token($i,$t){return $t==='synthetic-token';} static function is_payable($i){return $i->status==='issued' && $i->balance>0;} }
class MBS_Series { static function get($ref){return (object)array('payment_method'=>$GLOBALS['offline']?'offline_bacs':'online');} }
class MBS_Money { static function format($minor){return 'GBP '.number_format($minor/100,2);} static function decimal($minor){return number_format($minor/100,2,'.','');} }
class MBS_Invoice_Reservation {
 static function acquire($i,$ref){$GLOBALS['acquires']++;return $GLOBALS['claim'];}
 static function release($ref,$claim,$reason){$GLOBALS['releases']++;return true;}
}
class IntentSession { function get($key,$default){return $default;} function set($key,$value){$GLOBALS['session_writes']++;} }
class IntentCart { function empty_cart(){$GLOBALS['cart_writes']++;} function add_to_cart(...$args){$GLOBALS['cart_writes']++;return $GLOBALS['cart_success'];} }
function WC(){return $GLOBALS['woo'];}
function get_option($key,$default=0){return 9;}
function get_post($id){return (object)array();}
function get_post_type($id){return 'product';}
function wc_get_product($id){return new class { function get_status(){return 'publish';} };}
function sanitize_text_field($v){return is_scalar($v)?strip_tags($v):'';}
function wp_unslash($v){return $v;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function get_bloginfo($key){return 'Synthetic Venue';}
function wc_get_checkout_url(){return 'https://venue.invalid/checkout/';}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
function wp_nonce_field($action,$name,$referer){echo '<input name="'.$name.'" value="'.hash('sha256',$action).'">';}
function wp_verify_nonce($nonce,$action){return hash_equals(hash('sha256',$action),(string)$nonce);}
function nocache_headers(){}
function add_filter(...$args){}
function add_action(...$args){}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_die($message,$title='', $args=array()){throw new IntentStop($message,$args['response']??500);}
function wp_safe_redirect($url,$status){throw new IntentStop($url,$status);}
require MBS_PLUGIN_DIR.'includes/class-woo-payment.php';
$checks=0;
function intent_assert($ok,$label){global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function intent_request($method,$post=array(),$token='synthetic-token'){
 $GLOBALS['invoice']=(object)array('invoice_ref'=>'INT-PAY','version'=>3,'status'=>'issued','balance'=>2500,'series_ref'=>'');
 $GLOBALS['woo']=(object)array('session'=>new IntentSession(),'cart'=>new IntentCart());
 foreach(array('acquires','releases','session_writes','cart_writes') as $key)$GLOBALS[$key]=0;
 $GLOBALS['claim']=array('reservation_ref'=>'synthetic-claim','status'=>'active');$GLOBALS['cart_success']=true;$GLOBALS['offline']=false;
 $_SERVER['REQUEST_METHOD']=$method;$_GET=array('mbs_invoice_pay'=>'1','invoice_ref'=>'INT-PAY','invoice_token'=>$token);$_POST=$post;
}
function intent_run(){try{MBS_Woo_Payment::handle_payment_redirect();throw new RuntimeException('Handler fell through');}catch(IntentStop $stop){return $stop;}}
function intent_unchanged(){intent_assert($GLOBALS['acquires']===0 && $GLOBALS['cart_writes']===0 && $GLOBALS['session_writes']===0,'Read/invalid request mutated checkout');}
$form=array('invoice_ref'=>'INT-PAY','invoice_token'=>'synthetic-token','mbs_invoice_confirm'=>'1','_mbs_invoice_nonce'=>hash('sha256','mbs_invoice_checkout:INT-PAY:3:2500'));
foreach(array('HEAD','GET','GET') as $method){intent_request($method);$stop=intent_run();intent_assert($stop->status===200,'Probe must succeed read-only');intent_unchanged();if($method==='GET'){intent_assert(strpos($stop->getMessage(),'Continue to secure checkout')!==false,'GET needs a deliberate button');intent_assert(strpos($stop->getMessage(),'method="post"')!==false,'Confirmation submits POST');}}
intent_request('PUT');intent_assert(intent_run()->status===405,'Reject unsupported method');intent_unchanged();
intent_request('GET',array(),'wrong');intent_assert(intent_run()->status===500,'Reject invalid bearer');intent_unchanged();
foreach(array(array(),array_merge($form,array('_mbs_invoice_nonce'=>'bad')),array_merge($form,array('mbs_invoice_confirm'=>'0')),array_merge($form,array('invoice_token'=>'bad'))) as $post){intent_request('POST',$post);intent_assert(intent_run()->status>=400,'Reject invalid confirmation');intent_unchanged();}
foreach(array('version','balance') as $changed){intent_request('POST',$form);$GLOBALS['invoice']->$changed++;intent_assert(intent_run()->status===403,'Reconfirm stale invoice version/balance');intent_unchanged();}
intent_request('POST',$form);$GLOBALS['invoice']->status='paid';intent_assert(intent_run()->status>=400,'Settled invoice cannot reserve');intent_unchanged();
intent_request('POST',$form);$GLOBALS['invoice']->series_ref='INT-SER';$GLOBALS['offline']=true;intent_assert(intent_run()->status>=400,'BACS invoice cannot reserve');intent_unchanged();
intent_request('POST',$form);$GLOBALS['woo']->session=null;intent_assert(intent_run()->status===503,'Missing session must fail before reserving');intent_unchanged();
intent_request('POST',$form);intent_assert(intent_run()->status===303,'Valid deliberate POST redirects without reposting');intent_assert($GLOBALS['acquires']===1 && $GLOBALS['cart_writes']===2 && $GLOBALS['session_writes']===1,'POST reserves and prepares one cart');
intent_request('POST',$form);$GLOBALS['claim']=new WP_Error('invoice_payment_reserved');intent_assert(intent_run()->status===409,'Keep competing checkout blocked');intent_assert($GLOBALS['cart_writes']===0 && $GLOBALS['releases']===0,'Never clear cart or release another owner');
intent_request('POST',$form);$GLOBALS['claim']['status']='bound';intent_assert(intent_run()->status===409,'Do not reuse an authoritative order');intent_assert($GLOBALS['cart_writes']===0 && $GLOBALS['releases']===0,'Bound order remains untouched');
intent_request('POST',$form);$GLOBALS['cart_success']=false;intent_assert(intent_run()->status===503 && $GLOBALS['releases']===1,'Cart failure releases only this active claim');
echo "OK: $checks invoice intent HTTP/side-effect assertions passed.\n";
