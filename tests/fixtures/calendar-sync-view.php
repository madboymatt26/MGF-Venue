<?php
// Isolated render fixture. No WordPress installation, production accounts or Google traffic.
define('ABSPATH',__DIR__);
define('MBS_PLUGIN_DIR',dirname(__DIR__,2).'/wp-plugin/mathlin-booking/');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function admin_url($p){return '/'.$p;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function selected($a,$b){if($a===$b)echo 'selected';}
function checked($v){if($v)echo 'checked';}
function get_page_by_path($p){return null;}
class MBS_Admin {static function brand_mark(){return '<span aria-hidden="true">◆ </span>';}}
class MBS_Google_Calendar {
    static function form_start($op){echo '<form method="post" action="/fixture-no-write"><input type="hidden" name="operation" value="'.esc_attr($op).'">';}
    static function history(){return [['at'=>'2026-10-19T12:00:00Z','action'=>'protect','ref'=>'MBS-TEST','result'=>'Protected reference saved; Google unchanged.']];}
    static function estate_only($s){return $s['events'];}
    static function render(){
        $s=[];$notice='Fixture comparison — no live data';$problem='';$calendars=[['id'=>'test-calendar','summary'=>'Estate shared calendar','accessRole'=>'writer']];
        $status=['enabled'=>false,'calendar'=>'Estate shared calendar','calendar_id'=>'test-calendar','connected'=>true,'configured'=>true,'secure_storage_ready'=>true,'last_result'=>'No Google entries changed.','last_success'=>'','redirect_uri'=>'https://example.invalid/callback'];
        $event=['id'=>'estate1','summary'=>'District camp <script>alert(1)</script>','start'=>['date'=>'2026-10-24'],'end'=>['date'=>'2026-10-27'],'location'=>'Cabin'];
        $want=$event;$want['summary']='Cabin — District Scouts';$ops=[];
        $family=[];for($i=1;$i<=6;$i++)$family[]=array_merge($event,['id'=>'family'.$i,'summary'=>'Example whole-site camp','location'=>'Whole park','htmlLink'=>'https://calendar.google.com/calendar/event?eid=fictional'.$i]);
        foreach(['Cabin','Bomb Hole','Paddock','Woodland','Field','Campfire'] as $i=>$area)$ops[]=['ref'=>'MBS-GROUP'.($i+1),'action'=>'review','want'=>array_merge($want,['summary'=>$area.' — Example Scouts']),'event'=>null,'reason'=>'Overlaps require review.','hash'=>'family'.$i,'candidates'=>$family];
        for($i=1;$i<=28;$i++)$ops[]=['ref'=>'MBS-TEST'.$i,'action'=>'review','want'=>$want,'event'=>null,'reason'=>'Existing estate entry overlaps; review area and dates.','hash'=>'fixture'.$i,'candidates'=>[$event,array_merge($event,['id'=>'estate'.$i.'-other','location'=>'Paddock'])]];
        $ops[]=['ref'=>'MBS-LINKED','action'=>'matched','want'=>$want,'event'=>$event,'reason'=>'Protected reference; estate unchanged.','hash'=>'linked','candidates'=>[]];
        $preview=['snapshot'=>['ops'=>$ops,'events'=>array_merge([$event],$family)]];
        include MBS_PLUGIN_DIR.'admin/views/google-calendar.php';
    }
}
if(PHP_SAPI==='cli-server'){
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Calendar Sync — render fixture</title><link rel="stylesheet" href="/wp-plugin/mathlin-booking/admin/admin.css"><style>body{font:14px system-ui;background:#f0f0f1;margin:24px}.widefat{border-collapse:collapse;width:100%;background:white}.widefat td,.widefat th{padding:14px;border-bottom:1px solid #ddd;text-align:left}.button,.nav-tab{padding:10px 14px;background:white;border:1px solid #aaa;text-decoration:none;color:#542087;border-radius:4px;display:inline-block}.nav-tab-active,.button-primary{background:#6512b7;color:white}.nav-tab-wrapper{display:flex;gap:8px;flex-wrap:wrap}form{margin:12px 0}details{padding:8px 0}input,select{padding:8px}</style></head><body>';
    MBS_Google_Calendar::render();echo '</body></html>';
}
