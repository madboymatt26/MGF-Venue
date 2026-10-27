<?php
define('ABSPATH',__DIR__);$options=array();$checks=0;
class WP_Error{public $code;function __construct($c,$m){$this->code=$c;}}
function is_wp_error($v){return$v instanceof WP_Error;}
function get_option($k,$d=false){return$GLOBALS['options'][$k]??$d;}
function add_option($k,$v,$unused='',$autoload=false){if(isset($GLOBALS['options'][$k]))return false;if($autoload!==false)throw new RuntimeException('Private CSV must never autoload');$GLOBALS['options'][$k]=$v;return true;}
function wp_generate_uuid4(){static$n=0;return'fixture-'.++$n;}
function wp_cache_delete($k,$g){}
function maybe_serialize($v){return serialize($v);}
class ExportDB{public$options='options';function prepare($sql,...$args){return$args;}function query($args){list($key,$value)=$args;if(isset($GLOBALS['options'][$key])&&serialize($GLOBALS['options'][$key])===$value){unset($GLOBALS['options'][$key]);return 1;}return 0;}}
$wpdb=new ExportDB();require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-export-snapshots.php';
function expect_export($ok,$m){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($m);}
$csv="Name,Amount\r\nExample,20.00\r\n";$job=MBS_Export_Snapshots::save($csv,'xero',17);
expect_export(!is_wp_error($job),'Snapshot saved');
expect_export(MBS_Export_Snapshots::read(17,$job['slot'],$job['id'])['csv']===$csv,'Frozen bytes returned');
expect_export(is_wp_error(MBS_Export_Snapshots::read(18,$job['slot'],$job['id'])),'Different account cannot read snapshot');
expect_export(is_wp_error(MBS_Export_Snapshots::read(17,$job['slot'],'wrong')),'Wrong id cannot read snapshot');
expect_export(is_wp_error(MBS_Export_Snapshots::read(17,array(),'wrong')),'Malformed slot refused');
expect_export(MBS_Export_Snapshots::byte_range('bytes=4-9',20)===array(4,9,true),'Explicit retry byte range');
expect_export(MBS_Export_Snapshots::byte_range('bytes=4-',20)===array(4,19,true),'Open-ended range');
expect_export(MBS_Export_Snapshots::byte_range('bytes=-5',20)===array(15,19,true),'Suffix range');
foreach(array('bytes=0-1,3-4','bytes=20-','bytes=10-2','bytes=-0','garbage')as$bad)expect_export(is_wp_error(MBS_Export_Snapshots::byte_range($bad,20)),'Invalid range rejected');
$key='mbs_export_snapshot_17_'.$job['slot'];$options[$key]['csv'].='changed';expect_export(is_wp_error(MBS_Export_Snapshots::read(17,$job['slot'],$job['id'])),'Checksum detects corrupted snapshot');
$options[$key]['expires']=time()-1;expect_export(is_wp_error(MBS_Export_Snapshots::read(17,$job['slot'],$job['id']))&&!isset($options[$key]),'Expired snapshot removed by compare-and-set');
for($i=0;$i<3;$i++)expect_export(!is_wp_error(MBS_Export_Snapshots::save($csv,'sage',17)),'Bounded export slot created');
expect_export(is_wp_error(MBS_Export_Snapshots::save($csv,'sage',17)),'Quota enforced without overwriting active downloads');
expect_export(is_wp_error(MBS_Export_Snapshots::save(str_repeat('x',MBS_Export_Snapshots::MAX_BYTES+1),'sage',19)),'Oversized snapshot rejected');
echo "PASS {$checks} private resumable export assertions\n";
