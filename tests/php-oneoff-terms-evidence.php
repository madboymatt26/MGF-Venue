<?php
define('ABSPATH',__DIR__);$options=array('mbs_terms_page_id'=>42,'mbs_terms_text'=>'<p>Original terms</p>');
function get_option($k,$d=false){return$GLOBALS['options'][$k]??$d;}
function get_post($id){return(object)array('post_content'=>'[mathlin_terms]');}
function wp_kses_post($html){return$html;}
function wp_json_encode($value){return json_encode($value);}
class WP_Error{public $code;public function __construct($code,$message){$this->code=$code;}}
class MBS_Bookings{public static function get_default_terms(){return'Default';}public static function parse_venue_placeholders($text){return$text;}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-terms-acceptance.php';
$old=MBS_Terms_Acceptance::current();$accepted=MBS_Terms_Acceptance::capture($old['hash']);if(empty($accepted['accepted_at'])||$accepted['source']!=='hirer_request')throw new RuntimeException('Missing acceptance evidence');
$options['mbs_terms_text']='<p>Updated terms</p>';if(!(MBS_Terms_Acceptance::capture($old['hash'])instanceof WP_Error))throw new RuntimeException('Stale terms accepted');
if($accepted['terms_html']!=='<p>Original terms</p>')throw new RuntimeException('Historical terms mutated');
if(!(MBS_Terms_Acceptance::capture(array())instanceof WP_Error))throw new RuntimeException('Invalid version accepted');
echo "PASS immutable one-off acceptance and stale-form rejection\n";
