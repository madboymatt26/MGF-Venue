<?php
define('ABSPATH',__DIR__); $checks=0; $host='needhamscouts.uk';$hooks=array();$wpdb=(object)array('prefix'=>'wp_');
class MBS_Campsite_Policy {static function enabled(){return true;}}
function home_url(){global $host;return 'https://'.$host;} function wp_parse_url($u,$p){return parse_url($u,$p);}
function add_filter(...$args){global $hooks;$hooks[]=$args;}function add_action(...$args){global $hooks;$hooks[]=$args;}
function current_user_can($c){return true;}function esc_html($v){return $v;}function wp_doing_ajax(){return false;}
function wp_die($m,$title,$args){throw new RuntimeException($m,$args['response']);}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-legacy-read-only.php';
function expect($v,$m){global $checks;$checks++;if(!$v){fwrite(STDERR,"FAIL $m\n");exit(1);}}
MBS_Legacy_Read_Only::init();expect(!MBS_Legacy_Read_Only::enabled()&&!$hooks,'Needham untouched');$host='stowmarketscouts.org.uk';MBS_Legacy_Read_Only::init();expect(MBS_Legacy_Read_Only::enabled(),'Stowmarket guard on');
foreach(array('UPDATE wp_booking SET form=1','DELETE FROM `wp_bookingdates` WHERE booking_id=1','INSERT INTO wp_booking (form) VALUES (1)','REPLACE INTO wp_bookingtypes VALUES (1)','TRUNCATE TABLE wp_booking','ALTER TABLE wp_bookingdates ADD x INT','/* comment */ DROP TABLE wp_booking','WITH x AS (SELECT 1) UPDATE wp_booking SET form=1')as$sql)expect(MBS_Legacy_Read_Only::is_legacy_write($sql,'wp_'),'Legacy mutation blocked');
foreach(array('SELECT * FROM wp_booking','SHOW COLUMNS FROM wp_bookingdates','DESCRIBE wp_bookingtypes','EXPLAIN SELECT * FROM wp_booking','UPDATE wp_mathlin_bookings SET status=1',"INSERT INTO wp_audit (message) VALUES ('UPDATE wp_booking SET form=1')",'UPDATE other_booking SET form=1',"INSERT INTO wp_audit (message) VALUES ('O".chr(92)."'Brien wp_booking')")as$sql)expect(!MBS_Legacy_Read_Only::is_legacy_write($sql,'wp_'),'Reads and other plugins allowed: '.$sql);
expect(MBS_Legacy_Read_Only::legacy_stack(array(array('file'=>'C:\\site\\wp-content\\plugins\\booking.personal.10.8\\core.php'))),'Original Personal plugin mail identified');
expect(MBS_Legacy_Read_Only::legacy_stack(array(array('file'=>'/site/wp-content/plugins/booking/core.php'))),'Original free plugin mail identified');
expect(!MBS_Legacy_Read_Only::legacy_stack(array(array('file'=>'/site/wp-content/plugins/mathlin-booking/includes/class-email.php'))),'MGF mail unaffected');
try{MBS_Legacy_Read_Only::guard_query('UPDATE wp_booking SET form=1');expect(false,'Write should abort');}catch(RuntimeException$e){expect($e->getCode()===409,'Write aborts before database');}
expect(MBS_Legacy_Read_Only::guard_query('SELECT * FROM wp_booking')==='SELECT * FROM wp_booking','Original listing can read');
$main=file_get_contents(dirname(__DIR__).'/wp-plugin/mathlin-booking/mathlin-booking.php');expect(strpos($main,'MBS_Legacy_Comparison')===false,'Replacement interface not loaded');
echo "PASS $checks original-plugin read-only checks\n";
