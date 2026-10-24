<?php
require __DIR__.'/php-google-calendar-worker.php';
class TargetProjectionDB extends FixtureDB {
    public $reads=0;
    public function get_results($sql){$this->reads++;if(preg_match("/WHERE ref='([^']+)' LIMIT 2/",$sql,$m))return array_values(array_filter($this->rows,static function($b)use($m){return$b->ref===$m[1];}));return$this->rows;}
}
$wpdb=new TargetProjectionDB();ready();$s=get_option('mbs_google_calendar');
for($i=0;$i<300;$i++){$other=fixture();$other->ref='OTHER-'.$i;$wpdb->rows[]=$other;}
$p=invoke('booking_projection','MBS-F1',$s);check(count($p['source'])===1&&$wpdb->reads===1,'native managed projection reads one indexed source, not 301 rows');
$wpdb->rows[0]->custom_fields=json_encode(array('_mbs_legacy'=>array('plugin'=>'wpbc','booking_id'=>1)));$wpdb->reads=0;$p=invoke('booking_projection','MBS-F1',$s);check(count($p['source'])===301&&$wpdb->reads===2,'legacy projection retains full provenance graph');
echo "PASS indexed target revalidation and legacy graph preservation\n";
