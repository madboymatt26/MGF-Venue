<?php
// Fictional render only: no database, emails, calendars or booking writes.
define('ABSPATH', __DIR__); define('MBS_PLUGIN_DIR', dirname(__DIR__,2).'/wp-plugin/mathlin-booking/');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_html($v);}
function admin_url($v){return '/'.$v;} function wp_date($v){return '2026-10-19';}
function get_option($k,$default=null){return $k==='mbs_scout_nights_enabled'? !MBS_Campsite_Policy::enabled() : $default;}
class MBS_Admin{static function brand_mark(){return '◆ ';}}
class MBS_Campsite_Policy{static function enabled(){return ($_GET['mode']??'camp')==='camp';}}
class MBS_Payment_Features{static function free_hire(){return MBS_Campsite_Policy::enabled();}}
class MBS_Bookings{static function get_spaces(){return ['Whole Park'=>['capacity'=>1000], 'Cabin'=>['capacity'=>100]];} static function get_pricing_tiers(){return ['standard'=>['label'=>'Standard'], 'community'=>['label'=>'Community']];}}
class MBS_Custom_Fields{static function render_form_fields(){echo '<label>Test custom field <input name="custom_fields[test]" required></label>';}}
function entry_fixture(){include MBS_PLUGIN_DIR.'admin/views/add-booking.php';}
if(PHP_SAPI==='cli-server'){
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/booking-entry.js'){header('Content-Type: application/javascript');readfile(MBS_PLUGIN_DIR.'admin/booking-entry.js');return;}
    if($path!=='/') {http_response_code(404);exit;}
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Add booking — fictional preview</title><style>body{font:14px system-ui;background:#f0f0f1;padding:20px}.nms-card{background:#fff;border:1px solid #ddd;border-radius:8px}.button{padding:10px 16px;border:1px solid #aaa;border-radius:4px}.button-primary{background:#7413dc;color:white}input,select,textarea{box-sizing:border-box;padding:8px;border:1px solid #888;border-radius:4px}h2{color:#4b137d}</style></head><body><p>Fictional render only — no live booking system connected.</p>';
    entry_fixture();echo '<script>const MBS_Entry={url:"/disabled/",nonce:"fictional"};</script><script src="/booking-entry.js"></script></body></html>';
}
