<?php
// Execute the handler: core login must retain security-plugin authentication.
define('ABSPATH',__DIR__);
class LoginResponse extends RuntimeException {public $data;function __construct($data){$this->data=$data;}}
function check_ajax_referer($a,$b){if($a!=='mbs_public_nonce'||$b!=='nonce')throw new RuntimeException('nonce mismatch');}
function wp_login_url($redirect){return 'https://example.invalid/wp-login.php?redirect_to='.rawurlencode($redirect);}
function wp_send_json_success($data){throw new LoginResponse($data);}
class MBS_Hirer_Identity {static function manage_url(){return 'https://example.invalid/manage/';}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-hirer-portal.php';
try{(new MBS_Hirer_Portal())->ajax_login();throw new RuntimeException('No response');}catch(LoginResponse $r){if(strpos($r->data['login_url'],'https://example.invalid/wp-login.php?')!==0)throw new RuntimeException('core login not used');}
$root=__DIR__.'/../wp-plugin/mathlin-booking/';
$source=file_get_contents($root.'includes/class-hirer-portal.php');
if(strpos($source,'wp_signon(')!==false)throw new RuntimeException('Custom credential processing must be removed');
foreach(array('manage.php','hirer-login.php')as$view){$s=file_get_contents($root.'public/views/'.$view);if(strpos($s,'res.data.login_url')===false)throw new RuntimeException('Cached-form redirect unsupported: '.$view);}
echo "PORTAL_CORE_LOGIN_OK (actual handler, nonce and both clients)\n";
