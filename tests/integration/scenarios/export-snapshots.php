<?php
/** Disposable database only. Concurrent writer cannot change later export pages. */
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$email='export-'.wp_generate_uuid4().'@example.invalid';$checks=0;
$assert=static function($ok,$m)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($m);};
$writer=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$writer->suppress_errors(true);$page=0;$changed=false;
$filter=static function($sql)use(&$page,&$changed,$writer,$table,$email){
    if(strpos($sql,'FROM '.$table.' WHERE id>')!==false&&strpos($sql,'ORDER BY id LIMIT 500')!==false){
        $page++;if($page===2){$changed=$writer->query($writer->prepare("UPDATE {$table} SET amount='2.00' WHERE email=%s",$email))===505;}
    }return$sql;
};
try{
    for($i=0;$i<505;$i++)$assert($wpdb->insert($table,array('ref'=>'EXP-'.substr(hash('sha256',$email.'|'.$i),0,12),'status'=>'paid','name'=>'Synthetic export','email'=>$email,'space'=>'Main Hall','booking_date'=>'2096-01-01','booking_date_end'=>'2096-01-01','start_time'=>'10:00:00','end_time'=>'11:00:00','purpose'=>'Synthetic snapshot','amount'=>'1.00'))===1,'Seed export fixture');
    add_filter('query',$filter,PHP_INT_MAX);
    $records=MBS_Accounting_Export::consistent_records('2096-01-01','2096-01-01');
    remove_filter('query',$filter,PHP_INT_MAX);
    $mine=array_values(array_filter($records,static function($r)use($email){return$r->email===$email;}));
    $assert($changed&&$page>=2,'Separate connection modified bookings between pages');
    $assert(count($mine)===505,'Every snapshot page exported once');
    foreach($mine as$r)$assert($r->total_decimal==='1.00','Later page retained consistent original amount');
    $assert($wpdb->get_var($wpdb->prepare("SELECT MIN(amount) FROM {$table} WHERE email=%s",$email))==='2.00','Writer change actually committed outside snapshot');
    echo "EXPORT_SNAPSHOTS_OK: {$checks} concurrent database assertions\n";
}finally{remove_filter('query',$filter,PHP_INT_MAX);$wpdb->query('ROLLBACK');$wpdb->delete($table,array('email'=>$email));$writer->close();}
