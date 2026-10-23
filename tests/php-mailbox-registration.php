<?php
define('ABSPATH',__DIR__);
class RegistrationResponse extends RuntimeException{public $data;function __construct($data){$this->data=$data;}}
function check_ajax_referer($action,$field){if($action!=='mbs_public_nonce')throw new RuntimeException('Nonce required');}
function get_transient($key){return 0;}
function set_transient($key,$value,$ttl){return true;}
function sanitize_text_field($v){return trim(strip_tags($v));}
function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function email_exists($v){return false;}
function wp_generate_password($length,$special=false,$extra=false){return str_repeat('S',$length);}
function wp_create_user($login,$pass,$email){$GLOBALS['created']=array($login,$pass,$email);return 42;}
function is_wp_error($v){return false;}
function update_user_meta($id,$key,$value){$GLOBALS['metadata'][$key]=$value;}
class WP_User{function __construct($id){}function set_role($role){$GLOBALS['role']=$role;}}
class MBS_Campsite_Policy{static function enabled(){return false;}}
class MBS_Hirer_Identity{static function send_link($email,$id){$GLOBALS['proof_requested']=array($email,$id);return true;}}
function wp_send_json_success($data){throw new RegistrationResponse($data);}
function wp_send_json_error($data){throw new RuntimeException('Unexpected registration rejection');}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-hirer-portal.php';
$_POST=array('name'=>'Synthetic Owner','email'=>'owner@example.invalid','password'=>'Attacker-chosen-password');$_SERVER['REMOTE_ADDR']='192.0.2.1';
try{(new MBS_Hirer_Portal())->ajax_register();throw new RuntimeException('No response');}catch(RegistrationResponse $result){
    if(empty($result->data['verification_required']))throw new RuntimeException('Verification notice required');
    if($created[1]===$_POST['password']||strlen($created[1])<32)throw new RuntimeException('Pre-registrant can choose a usable password');
    if($proof_requested!==array('owner@example.invalid',42))throw new RuntimeException('Proof must go to the exact account mailbox');
    if(isset($metadata['mbs_verified_email']))throw new RuntimeException('Registration must not set verification');
}
// Auth-cookie/current-user/linking functions deliberately do not exist: any
// regression to automatic login/record-claiming would have failed this process.
echo "MAILBOX_REGISTRATION_OK (actual handler, no automatic login or claims)\n";
