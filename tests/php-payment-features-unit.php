<?php
define('ABSPATH',__DIR__);
$options=array(); $campsite=false; $checks=0;
class WP_Error { public $code,$message; function __construct($c,$m,$d=null){$this->code=$c;$this->message=$m;} function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=null){global $options;$options[$k]=$v;}
function add_action(...$args){}
function add_filter(...$args){}
class MBS_Campsite_Policy {static function enabled(){global $campsite;return $campsite;}}
class Request {public $route,$method,$data;function __construct($r,$m,$d=array()){$this->route=$r;$this->method=$m;$this->data=$d;} function get_route(){return $this->route;}function get_method(){return $this->method;}function get_json_params(){return $this->data;}function get_params(){return $this->data;}function get_param($k){return $this->data[$k]??'';}}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-payment-features.php';
function expect($v,$m){global $checks;$checks++;if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
expect(MBS_Payment_Features::enabled(),'Existing paid venues unchanged by default');
foreach(MBS_Payment_Features::definitions() as $key=>$label) expect(MBS_Payment_Features::enabled($key),'Default enabled: '.$key);
$campsite=true;
foreach(MBS_Payment_Features::definitions() as $key=>$label) expect(!MBS_Payment_Features::enabled($key),'Campsite default off: '.$key);
$result=MBS_Payment_Features::save(array('payments_enabled'=>1,'payment_feature_online'=>0));
expect(!is_wp_error($result)&&MBS_Payment_Features::enabled(),'Explicit campsite opt-in');
expect(!MBS_Payment_Features::enabled('online'),'Independent checkout switch');
expect(MBS_Payment_Features::free_hire(),'Campsite hire remains free when additional billing enabled');
$before=$options;
expect(is_wp_error(MBS_Payment_Features::save(array('payments_enabled'=>0,'payment_feature_manual'=>'yes'))),'Invalid bool rejected');
expect($options===$before,'Validation atomic: no partial updates');
MBS_Payment_Features::save(array('payment_feature_invoices'=>0));
expect(!MBS_Payment_Features::enabled('additional_charges'),'Charges depend on invoices');
expect(MBS_Payment_Features::enabled('manual'),'Sibling setting preserved');
MBS_Payment_Features::save(array('payments_enabled'=>0));
expect($options['mbs_payment_feature_manual']??1,'Master does not erase child preference');
$policy=new MBS_Payment_Features;
foreach(array(array('/mathlin/v1/admin/additional-charges','POST'),array('/mathlin/v1/admin/invoices/INV-1/payments','POST'),array('/mathlin/v1/admin/bookings/MBS-1/status','POST',array('status'=>'paid')),array('/mathlin/v1/admin/actions/record_invoice_manual_payment','POST',array('action'=>'record_invoice_manual_payment'))) as $case){
    expect(is_wp_error($policy->guard_rest(null,null,new Request(...$case))),'Disabled write blocked: '.$case[0]);
}
expect($policy->guard_rest(null,null,new Request('/mathlin/v1/admin/additional-charges/INV-1/document','GET'))===null,'Historical documents readable');
expect($policy->guard_rest(null,null,new Request('/mathlin/v1/admin/bookings/MBS-1/status','POST',array('status'=>'confirmed')))===null,'Nonfinancial approval route not blocked');
expect($policy->guard_rest(null,null,new Request('/another/v1/payments','POST'))===null,'Other plugins untouched');
expect(is_wp_error($policy->guard_rest(null,null,new Request('/mathlin/v1/admin/actions/update_status','POST',array('status'=>'paid')))),'MCP status blocked before WordPress URL params are attached');
expect(MBS_Payment_Features::action_feature('bulk_action',array('bulk_action'=>'paid'))==='manual','Bulk paid classified');
expect(MBS_Payment_Features::action_feature('configure_series_billing',array('billing_mode'=>'none','billing_treatment'=>'none'))==='','No-billing configuration allowed');
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-billing-ledger.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-additional-charges.php';
expect(is_wp_error(MBS_Billing_Ledger::create_draft_invoice(array(),'test')),'Direct ledger draft blocked before database');
expect(is_wp_error(MBS_Billing_Ledger::issue_invoice('test',1)),'Direct invoice issuance blocked before database');
expect(MBS_Additional_Charges::customer_for_booking(null,'x@example.invalid')===array(),'Disabled customer charge prompts absent');
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-invoice-payment.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-woo-payment.php';
expect(!MBS_Woo_Payment::is_available(),'Disabled checkout unavailable');
expect(MBS_Woo_Payment::generate_payment_url((object)array())==='','No new booking payment link');
echo "PASS $checks payment-feature checks\n";
