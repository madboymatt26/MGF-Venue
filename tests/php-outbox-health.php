<?php
define('ABSPATH',__DIR__);
function current_time($type){return '2026-10-27 10:00:00';}
class HealthDB{public $prefix='test_';public $last_error='';public $calls=0;public $fail=false;function prepare($sql,...$args){return$sql;}function get_row($sql){$this->calls++;if($this->fail){$this->last_error='synthetic read failure';return null;}return(object)array('pending'=>2,'processing'=>1,'sent'=>10,'failed'=>0,'oldest_pending_at'=>'2026-10-26 09:00:00','next_due_at'=>'2026-10-27 10:05:00','expired_leases'=>1);}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-email-queue.php';
$wpdb=new HealthDB();$s=MBS_Email_Queue::get_stats();
if($wpdb->calls!==1||!$s['available']||$s['pending']!==2||$s['expired_leases']!==1||$s['next_due_at']!=='2026-10-27 10:05:00')throw new RuntimeException('Outbox timing/counts must come from one aggregate');
$wpdb->fail=true;$s=MBS_Email_Queue::get_stats();if($s['available']!==false||$s['pending']!==null||$s['failed']!==null)throw new RuntimeException('Unknown outbox state must not masquerade as zero failures');
echo "PASS outbox aggregate timing and failed-read diagnostics\n";
