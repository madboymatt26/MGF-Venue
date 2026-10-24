<?php
define('ABSPATH',__DIR__);define('MBS_TABLE','bookings');define('MBS_SERIES_TABLE','series');define('MBS_INVOICE_TABLE','invoices');define('MBS_PAYMENT_TRANSACTION_TABLE','transactions');
function wp_json_encode($v){return json_encode($v);}
class PrivacyFixtureDB {
    public $prefix='fixture_';public $queries=array();
    public function prepare($sql,...$args){$id='query-'.count($this->queries);$this->queries[$id]=array($sql,$args);return$id;}
    public function get_results($q){list($sql,$args)=$this->queries[$q];if(strpos($sql,'FROM fixture_bookings')===false)return array();$offset=$args[1];$rows=array();for($i=$offset;$i<min(250,$offset+100);$i++)$rows[]=(object)array('id'=>$i+1,'ref'=>'BOOKING-'.$i,'name'=>'Fixture','email'=>'test@example.test','phone'=>'','address'=>'','organisation'=>'','space'=>'Hall','booking_date'=>'2027-01-01','amount'=>10,'status'=>'paid','created_at'=>'2026-01-01');return$rows;}
}
$wpdb=new PrivacyFixtureDB();require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-bookings.php';
$all=array();for($page=1;$page<=3;$page++){$result=MBS_Bookings::gdpr_export_personal_data('test@example.test',$page);if($result['done']!==($page===3))throw new RuntimeException('Incorrect export completion at page '.$page);foreach($result['data']as$row)$all[]=$row['item_id'];}
if(count($all)!==250||count(array_unique($all))!==250)throw new RuntimeException('Export missing or repeating records');
echo "PASS privacy export returns all 250 records without duplication\n";
