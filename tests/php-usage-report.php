<?php
define('ABSPATH',__DIR__);$options=array();$checks=0;
function wp_timezone(){return new DateTimeZone('Europe/London');}
function get_option($key,$default=false){return$GLOBALS['options'][$key]??$default;}
function check($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
class MBS_Campsite_Policy{public static $enabled=true;public static function enabled(){return self::$enabled;}public static function span($b){return$b->span??'daily';}}
class MBS_Space_Selection{public static function members($space){return explode(' + ',$space);}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-usage-report.php';
$spaces=array('Whole Park'=>array('parent'=>null),'A'=>array('parent'=>'Whole Park'),'B'=>array('parent'=>'Whole Park'));
$b=(object)array('id'=>1,'ref'=>'B1','space'=>'Whole Park','booking_date'=>'2027-07-10','booking_date_end'=>'2027-07-12','start_time'=>'00:00:00','end_time'=>'23:59:00','all_day'=>1,'status'=>'confirmed','attendees'=>10);
$before=serialize($b);$report=MBS_Usage_Report::calculate(array($b),$spaces,'2027-07-10','2027-07-12');
foreach($report['rows']as$row)check($row['hours']===72.0&&$row['resource_days']===3&&$row['percent']===100.0,'three-day parent expands every resource');
check(serialize($b)===$before,'report does not mutate booking');
$report=MBS_Usage_Report::calculate(array($b),$spaces,'2027-07-11','2027-07-12');check($report['rows'][1]['hours']===48.0,'period clipping uses same numerator and denominator');
MBS_Campsite_Policy::$enabled=false;$a=clone$b;$a->space='A';$a->all_day=0;$a->booking_date_end=$a->booking_date;$a->start_time='10:00:00';$a->end_time='12:00:00';$c=clone$a;$c->ref='B2';$c->start_time='11:00:00';$c->end_time='13:00:00';
$report=MBS_Usage_Report::calculate(array($a,$c),$spaces,'2027-07-10','2027-07-10');
check($report['rows'][0]['hours']===3.0&&$report['rows'][1]['hours']===3.0,'overlapping physical hours unioned');
check($report['rows'][2]['hours']===0.0,'child does not occupy sibling');
check($report['rows'][1]['reservations']===2&&$report['rows'][1]['attendees_estimate']===20,'reservations and estimated people are not days');
$c->status='pending';$report=MBS_Usage_Report::calculate(array($c),$spaces,'2027-07-10','2027-07-10');check($report['rows'][0]['hours']===0.0,'pending requests excluded');
$bad=clone$a;$bad->booking_date='2027-02-30';$report=MBS_Usage_Report::calculate(array($bad),$spaces,'2027-07-10','2027-07-10');check($report['invalid_refs']===array('B1'),'invalid source reported explicitly');
$options['mbs_report_open_hour']=0;$options['mbs_report_close_hour']=24;$dst=clone$b;$dst->booking_date=$dst->booking_date_end='2027-03-28';$report=MBS_Usage_Report::calculate(array($dst),$spaces,'2027-03-28','2027-03-28');check($report['rows'][0]['hours']===23.0&&$report['rows'][0]['total']===23.0,'DST day uses actual elapsed occupied and available time');
echo "PASS {$checks} usage-report assertions\n";
