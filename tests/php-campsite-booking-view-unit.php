<?php
/** Rendered-view proof: campsite mode hides venue-only billing chrome and the
 * Home Assistant heating card on the individual booking page and settings,
 * while venue mode and campsite bookings with real money/documents keep them.
 * With MBS_PROOF_DIR set, the rendered HTML variants are written there. */
define( 'ABSPATH', __DIR__ );
define( 'MBS_VERSION', '3.48.5' );
define( 'MBS_TABLE', 'mathlin_bookings' );
$options = array();
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$a=null){global $options;$options[$k]=$v;return true;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function esc_url_raw($v){return (string)$v;}
function esc_textarea($v){return esc_html($v);}
function admin_url($p=''){return 'https://example.invalid/wp-admin/'.$p;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_create_nonce($a){return 'nonce';}
function wp_date($f,$t=null){return date($f,$t??strtotime('2026-11-22 12:00:00'));}
function wp_json_encode($v){return json_encode($v);}
function wp_parse_url($u){return parse_url($u);}
function wp_unslash($v){return $v;}
function checked($a,$b=true,$e=true){$s=($a==$b)?'checked="checked"':'';if($e)echo$s;return$s;}
function selected($a,$b=true,$e=true){$s=($a==$b)?'selected="selected"':'';if($e)echo$s;return$s;}
function current_user_can($c){return true;}
function get_post($id){return null;}
function get_posts($args){return array();}
function get_permalink($id){return '#';}
function wp_nonce_field($a=-1,$n='_wpnonce',$r=true,$e=true){$h='<input type="hidden" name="_wpnonce" value="nonce">';if($e)echo$h;return$h;}
function wp_create_nonce_stub(){return 'nonce';}
function wp_roles(){return (object)array('roles'=>array('administrator'=>array('name'=>'Administrator'),'approved_scout'=>array('name'=>'Approved Scout')));}
function rest_url($p=''){return 'https://example.invalid/wp-json/'.ltrim($p,'/');}
function home_url($p=''){return 'https://example.invalid/'.ltrim($p,'/');}
function wp_editor($content,$id,$args=array()){echo '<textarea id="'.esc_attr($id).'">'.esc_html($content).'</textarea>';}
function wp_dropdown_pages($args=array()){echo '<select name="page_id"></select>';}
$wpdb = new class { public $prefix = 'wp_'; };
class MBS_Bookings {
    public static function get_spaces(){return array('Main Scout Hall'=>array('rate_hourly'=>25,'rate_daily'=>150,'parent'=>null));}
    public static function get_booking_tier($b){return 'standard';}
    public static function get_tier_multiplier($t){return 1.0;}
    public static function get_tiered_kitchen_price($t){return 0;}
    public static function count_series($s){return 0;}
    public static function get_deposit_settings(){return array('enabled'=>true,'percentage'=>25);}
    public static function calculate_deposit($a){return $a*0.25;}
    public static function requires_full_payment($d){return false;}
    public static function status_label($s){return ucfirst(str_replace('_',' ',(string)$s));}
    public static function get_admin_email(){return 'coordinator@example.invalid';}
    public static function get_default_terms(){return '';}
    public static function get_kitchen_price(){return 0;}
    public static function get_pricing_tiers(){return array();}
}
class MBS_Series {
    public static function get($id){return null;}
    public static function billing_treatment_for_booking($b,$s=null){return 'one_off';}
}
class MBS_Access_Details {
    public static function readiness($b){return array('state'=>'not_due','action'=>'Nothing to send.','due_at'=>null);}
    public static function is_eligible_for_access($b){return false;}
}
class MBS_Email_Queue {
    const MAX_ATTEMPTS = 5;
    public static function get_booking_email_summary($id){return array('failed_count'=>0,'pending_count'=>0,'latest_access'=>null,'latest_failed'=>null);}
}
class MBS_Additional_Charges { public static function render_related($ref){echo '<div data-stub="additional-charges"></div>';} }
class MBS_Credit_Accounts {
    public static function render_booking($b){echo '<div data-stub="credit-accounts"></div>';}
    public static function supported(){return false;}
    public static function url($k='',$r=''){return '#';}
}
class MBS_Audit_Log {
    public static function get_for_booking($ref){return array();}
    public static function action_label($a){return (string)$a;}
}
class MBS_Custom_Fields { public static function render_admin_display($b){return '';} }
class MBS_Google_Calendar {
    public static function render_booking_card($b){return '';}
    public static function status(){return array('connected'=>false,'last_result'=>'','last_success'=>null,'enabled'=>false,'calendar'=>'');}
}
class MBS_Payment_Expiry { public static function render_booking($b){return '';} }
class MBS_Space_Selection { public static function selector($b){return '';} }
class MBS_Terms_Acceptance {
    public static function evidence($b){return null;}
    public static function render($b){return '';}
}
class MBS_Admin { public static function brand_mark(){return '';} }
class MBS_Feedback { public static function default_body(){return '';} }
class MBS_Integration_Health {
    public static function operational_settings(){return array('legacy_read_only'=>1,'report_open_hour'=>8,'report_close_hour'=>22,'buffers_enabled'=>0,'resource_buffers'=>array());}
    public static function payment_policy_warnings(){return array();}
}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-payment-features.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-homeassistant.php';

$checks=0;
function expect($condition,$message){global $checks;$checks++;if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function booking( $overrides = array() ) {
    return (object) array_merge( array(
        'id'=>101,'ref'=>'VIEW-1','status'=>'confirmed','name'=>'View Hirer','email'=>'h@example.invalid','phone'=>'',
        'space'=>'Main Scout Hall','booking_date'=>'2026-12-01','booking_date_end'=>'2026-12-01',
        'start_time'=>'10:00','end_time'=>'12:00','all_day'=>0,'attendees'=>10,'purpose'=>'View test',
        'amount'=>0,'amount_paid'=>0,'deposit_paid'=>0,'kitchen'=>0,'scout_use'=>1,'series_id'=>'',
        'custom_fields'=>'','current_invoice_document_id'=>0,'chase_count'=>0,'last_chased'=>null,'address'=>'',
        'invoice_number'=>'','organisation'=>'','notes'=>'','created_at'=>'2026-11-01 10:00:00','ha_notified'=>0,
    ), $overrides );
}
function render_single( $booking ) {
    $audit_entries = array();
    ob_start();
    require dirname(__DIR__).'/wp-plugin/mathlin-booking/admin/views/single.php';
    return ob_get_clean();
}
function render_settings() {
    ob_start();
    require dirname(__DIR__).'/wp-plugin/mathlin-booking/admin/views/settings.php';
    return ob_get_clean();
}
$proof_dir = getenv( 'MBS_PROOF_DIR' );
function proof( $name, $html ) { global $proof_dir; if ( $proof_dir ) file_put_contents( rtrim($proof_dir,'/').'/'.$name, $html ); }

// 1. Venue mode, free-of-charge booking: every section present.
$options = array();
$venue = render_single( booking() );
proof( 'booking-venue.html', $venue );
expect( strpos( $venue, 'href="#mbs-section-billing"' ) !== false, 'Venue booking keeps the Billing jump link' );
expect( strpos( $venue, 'id="mbs-section-billing"' ) !== false, 'Venue booking keeps the Billing & Extras section' );
expect( strpos( $venue, 'Billing &amp; Invoices' ) !== false, 'Venue booking keeps the Billing & Invoices action group' );
expect( strpos( $venue, 'mbs-heating-heading' ) !== false, 'Venue booking keeps the Home Assistant heating card' );

// 2. Campsite mode, free booking: venue-only chrome gone, core sections stay.
$options = array( 'mbs_campsite_mode' => 1 );
$camp = render_single( booking() );
proof( 'booking-campsite-free.html', $camp );
expect( strpos( $camp, 'href="#mbs-section-billing"' ) === false, 'Free campsite booking: no Billing jump link' );
expect( strpos( $camp, 'id="mbs-section-billing"' ) === false, 'Free campsite booking: no Billing & Extras section' );
expect( strpos( $camp, 'Billing &amp; Invoices' ) === false, 'Free campsite booking: no Billing & Invoices action group' );
expect( strpos( $camp, 'mbs-heating-heading' ) === false, 'Free campsite booking: no heating card' );
expect( strpos( $camp, 'No invoice is required' ) === false, 'Free campsite booking: no leftover billing notice' );
expect( strpos( $camp, 'mbs-section-details' ) !== false, 'Free campsite booking keeps the details section' );
expect( strpos( $camp, 'mbs-section-actions' ) !== false, 'Free campsite booking keeps workflow actions' );

// 3. Campsite booking carrying legacy money: billing chrome stays, but the
// venue-only payment actions stay feature-gated off.
$money = render_single( booking( array( 'amount' => '30.00' ) ) );
proof( 'booking-campsite-legacy-money.html', $money );
expect( strpos( $money, 'href="#mbs-section-billing"' ) !== false, 'Campsite booking with money keeps the Billing jump link' );
expect( strpos( $money, 'Billing &amp; Invoices' ) !== false, 'Campsite booking with money keeps the Billing group' );
expect( strpos( $money, 'View legacy invoice preview' ) !== false, 'Campsite booking with money keeps its invoice preview link' );
expect( strpos( $money, 'Mark Fully Paid' ) === false, 'Campsite booking with money: Mark Fully Paid stays gated off' );
expect( strpos( $money, 'Mark Deposit Paid' ) === false, 'Campsite booking with money: Mark Deposit Paid stays gated off' );
expect( strpos( $money, 'Chase Payment' ) === false, 'Campsite booking with money: Chase Payment stays gated off' );
expect( strpos( $money, 'mbs-heating-heading' ) === false, 'Campsite booking with money: heating card still hidden' );

// 4. Campsite booking with an issued document: document links survive.
$doc = render_single( booking( array( 'current_invoice_document_id' => 42 ) ) );
proof( 'booking-campsite-document.html', $doc );
expect( strpos( $doc, 'View issued invoice' ) !== false, 'Campsite booking with a document keeps View issued invoice' );
expect( strpos( $doc, 'Download PDF' ) !== false, 'Campsite booking with a document keeps Download PDF' );
expect( strpos( $doc, 'Mark Fully Paid' ) === false, 'Campsite booking with a document: payment actions stay gated off' );

// 5. Settings page: HA card hidden in campsite mode, visible in venue mode.
$options = array();
$settings_venue = render_settings();
proof( 'settings-venue.html', $settings_venue );
expect( strpos( $settings_venue, 'Home Assistant Integration' ) !== false, 'Venue settings show the HA card' );
$options = array( 'mbs_campsite_mode' => 1 );
$settings_camp = render_settings();
proof( 'settings-campsite.html', $settings_camp );
preg_match( '/<div class="nms-card"[^>]*>(?:(?!<div class="nms-card").)*Home Assistant Integration/s', $settings_camp, $ha_card );
expect( ! empty( $ha_card ) && strpos( $ha_card[0], 'hidden' ) !== false, 'Campsite settings hide the HA card (stored URL preserved)' );
expect( strpos( $settings_camp, 'name="ha_webhook_url"' ) !== false, 'Hidden HA card still submits its stored webhook URL' );

echo "OK: $checks campsite booking-view checks\n";
