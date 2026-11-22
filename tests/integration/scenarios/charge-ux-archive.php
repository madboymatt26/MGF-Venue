<?php
/** Disposable integration database only. */
if ( get_bloginfo('name') !== 'MGF Integration' ) throw new RuntimeException('Synthetic integration site required.');
wp_set_current_user(1);global $wpdb;$checks=0;
$assert=static function($ok,$message)use(&$checks){$checks++;if(is_wp_error($ok)||!$ok)throw new RuntimeException($message.(is_wp_error($ok)?': '.$ok->get_error_message():''));};
add_filter('pre_wp_mail','__return_true');
$ref='UX-'.strtoupper(substr(wp_generate_uuid4(),0,8));$now=current_time('mysql');
$assert($wpdb->insert($wpdb->prefix.MBS_TABLE,['ref'=>$ref,'name'=>'UX synthetic hirer','email'=>'ux@example.invalid','status'=>'archived','booking_date'=>'2020-12-30','booking_date_end'=>'2021-01-03','start_time'=>'10:00:00','end_time'=>'12:00:00','space'=>'Main Hall','created_at'=>$now,'updated_at'=>$now]),'Seed overlap stay');
$d=['related_ref'=>$ref,'amount'=>'25.00','category'=>'cleaning','description'=>'Extra cleaning','service_date'=>'2020-12-30','due_date'=>wp_date('Y-m-d',time()+14*DAY_IN_SECONDS),'contact_name'=>'UX synthetic hirer','contact_email'=>'ux@example.invalid'];
$draft=MBS_Additional_Charges::create($d,wp_generate_uuid4());$assert($draft,'Create draft');$d['amount']='99.00';$saved=MBS_Additional_Charges::update_draft($draft->invoice_ref,$d,$draft->version);$assert($saved,'Save edited amount');
$assert(is_wp_error(MBS_Additional_Charges::issue($saved->invoice_ref,$draft->version)),'Stale review cannot issue');
$i=MBS_Additional_Charges::issue($saved->invoice_ref,$saved->version);$assert($i,'Issue edited draft');$assert((int)$i->total_minor===9900,'Issued amount equals saved review');
update_option('mbs_payment_feature_online',0);$sent=MBS_Additional_Charges::send($i->invoice_ref,$i->version,wp_generate_uuid4());$assert($sent,'Offline invoice can send');
$mail=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'mathlin_email_queue WHERE reference_id=%d AND message_type=%s ORDER BY id DESC LIMIT 1',$i->id,'invoice_issued'));
$assert(strpos($mail->body,'#7413DC')!==false,'Invoice uses brand shell');$assert(strpos($mail->body,'offline payment')!==false,'Invoice explains offline route');$assert(strpos($mail->body,'Pay this additional charge</a>')===false,'No dead online CTA');
update_option('mbs_payment_feature_online',1);
$count=static function()use($wpdb,$i){return(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'mathlin_email_queue WHERE reference_id=%d',$i->id));};$before=$count();
$c=MBS_Additional_Charges::cancel($i->invoice_ref,$i->version,'Synthetic cancellation',wp_generate_uuid4());$assert($c,'Cancel issued unpaid');$assert($count()===$before,'Cancellation without notification creates no email');$assert((int)$c->paid_minor===0 && MBS_Billing_Ledger::balance_minor($c)===0,'Cancel unpaid changes no cash');
$cn=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_INVOICE_TABLE.' WHERE parent_invoice_id=%d ORDER BY id DESC LIMIT 1',$i->id));
$assert(MBS_Additional_Charges::send_credit($c->invoice_ref,$c->version,$cn->invoice_ref,wp_generate_uuid4()),'Explicit credit email');
$mail=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'mathlin_email_queue WHERE reference_id=%d AND message_type=%s ORDER BY id DESC LIMIT 1',$i->id,'additional_charge_credit'));
$assert(strpos($mail->body,'#7413DC')!==false && strpos($mail->body,'There is nothing to pay')!==false,'Branded unpaid cancellation clear');$assert(strpos($mail->body,'Pay this additional charge</a>')===false,'Cancelled email has no pay CTA');
$args=['status'=>'archived','search'=>$ref,'date_overlap'=>true,'date_from'=>'2021-01-01','date_to'=>'2021-01-02'];$assert(count(MBS_Bookings::get_all($args))===1,'Campsite stay overlaps range');$assert(MBS_Bookings::get_all(array_merge($args,['count_only'=>true]))===1,'Count matches exact filtered rows');
$args['date_from']='2021-01-04';$args['date_to']='2021-01-05';$assert(count(MBS_Bookings::get_all($args))===0,'Stay beyond end excluded');
for($n=0;$n<205;$n++)$assert($wpdb->insert($wpdb->prefix.MBS_TABLE,['ref'=>$ref.'-'.$n,'name'=>'Paging fixture','email'=>'ux@example.invalid','status'=>'archived','booking_date'=>'2019-01-01','space'=>'Main Hall','created_at'=>$now,'updated_at'=>$now]),'Seed archive page');
$args=['status'=>'archived','search'=>$ref,'limit'=>50,'order'=>'DESC'];$assert(MBS_Bookings::get_all(array_merge($args,['count_only'=>true]))===206,'All206 counted');$seen=[];for($n=0;$n<5;$n++)foreach(MBS_Bookings::get_all(array_merge($args,['offset'=>$n*50]))as$b)$seen[]=$b->ref;$assert(count($seen)===206 && count(array_unique($seen))===206,'Every archive accessible exactly once beyond old cap');
$assert(MBS_Bookings::get_all(array_merge($args,['space'=>'Unknown','count_only'=>true]))===0,'Space count scoped');
echo "Charge UX/archive runtime passed ($checks checks).\n";
