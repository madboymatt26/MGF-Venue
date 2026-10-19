<?php
define('ABSPATH',__DIR__);
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-custom-fields.php';
$booking=(object)array('custom_fields'=>json_encode(array('_mbs_whole_park_sources'=>array('MBS-X'),'_mbs_wpbc_source_id'=>123,'_mbs_campsite_arrangement'=>'selected','valid'=>array('label'=>'Permit holder','value'=>'A & B'),'malformed'=>'plain string')));
ob_start(); MBS_Custom_Fields::render_admin_display($booking); $html=ob_get_clean();
if(strpos($html,'Permit holder')===false||strpos($html,'A &amp; B')===false||strpos($html,'_mbs')!==false||strpos($html,'plain string')!==false){fwrite(STDERR,'Custom-field metadata separation failed');exit(1);}
foreach(array('null','"scalar"','[]','{"_internal":"private"}') as $json){ob_start();MBS_Custom_Fields::render_admin_display((object)array('custom_fields'=>$json));if(ob_get_clean()!==''){exit(1);}}
echo "PASS custom-field metadata display\n";
