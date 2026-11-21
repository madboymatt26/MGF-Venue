<?php
if(!defined('ABSPATH'))exit;

/** One read-only action list for the staff homepage and authorised integrations. */
class MBS_Attention {
    public static function summary($limit=20){
        global $wpdb;
        $limit=max(1,min(100,(int)$limit));$items=array();$issues=array();
        $bookings=$wpdb->get_results($wpdb->prepare('SELECT ref,booking_date,created_at FROM '.$wpdb->prefix.MBS_TABLE." WHERE status='pending' ORDER BY created_at ASC,id ASC LIMIT %d",$limit));
        if(!is_array($bookings)||$wpdb->last_error)$issues[]='Pending bookings could not be read.';
        else foreach($bookings as$b)$items[]=array('kind'=>'approval','ref'=>$b->ref,'since'=>$b->created_at,'finding'=>'Booking request awaiting a decision for '.$b->booking_date,'action'=>'Review and approve or reject','url'=>admin_url('admin.php?page=mathlin-booking&action=view&ref='.rawurlencode($b->ref)));
        $requests=$wpdb->get_results($wpdb->prepare('SELECT id,booking_ref,created_at FROM '.$wpdb->prefix.'mathlin_mod_requests'." WHERE status='pending' ORDER BY created_at ASC,id ASC LIMIT %d",$limit));
        if(!is_array($requests)||$wpdb->last_error)$issues[]='Change requests could not be read.';
        else foreach($requests as$r)$items[]=array('kind'=>'change_request','ref'=>$r->booking_ref,'since'=>$r->created_at,'finding'=>'Hirer change request awaiting review','action'=>'Review the request before changing the booking','url'=>admin_url('admin.php?page=mathlin-requests'));
        if(MBS_Payment_Features::enabled('reports')){
            $invoices=$wpdb->get_results($wpdb->prepare('SELECT invoice_ref,due_at FROM '.$wpdb->prefix.MBS_INVOICE_TABLE." WHERE document_type='invoice' AND status NOT IN ('draft','void') AND due_at IS NOT NULL AND due_at<%s AND total_minor>paid_minor+credited_minor ORDER BY due_at ASC,id ASC LIMIT %d",current_time('mysql'),$limit));
            if(!is_array($invoices)||$wpdb->last_error)$issues[]='Overdue invoices could not be read.';
            else foreach($invoices as$i)$items[]=array('kind'=>'payment','ref'=>$i->invoice_ref,'since'=>$i->due_at,'finding'=>'Issued invoice overdue under its existing due date','action'=>'Review outstanding balance and reconciliation before chasing','url'=>admin_url('admin.php?page=mathlin-analytics'));
        }
        if(class_exists('MBS_Payment_Expiry'))$items=array_merge($items,MBS_Payment_Expiry::needs_attention($limit));
        if(class_exists('MBS_Credit_Accounts'))$items=array_merge($items,MBS_Credit_Accounts::attention($limit));
        $mail=MBS_Email_Queue::get_stats();
        if(isset($mail['available'])&&!$mail['available'])$issues[]='Email outbox could not be read; delivery state is unknown.';
        if(!empty($mail['failed']))$items[]=array('kind'=>'email','ref'=>'Outbox','since'=>null,'finding'=>(int)$mail['failed'].' emails failed or became ineligible/stale','action'=>'Review delivery and current booking state before retrying; do not resend obsolete messages','url'=>admin_url('admin.php?page=mathlin-health&open_email_queue=failed'));
        foreach(MBS_Integration_Health::job_status()as$hook=>$state)if($state['needs_attention'])$items[]=array('kind'=>'scheduler','ref'=>$hook,'since'=>$state['started_at']??null,'finding'=>'Scheduled job is overdue or has not finished','action'=>'Inspect scheduler and hosting logs','url'=>admin_url('admin.php?page=mathlin-health'));
        $google=MBS_Google_Calendar::status();
        if($google['connected']&&preg_match('/(\d+) (?:need|needs) review/',$google['last_result'],$match)&&(int)$match[1]>0)$items[]=array('kind'=>'calendar','ref'=>'Protected calendar references','since'=>$google['last_success'],'finding'=>$match[1].' references need a decision; this does not mean sync is paused','action'=>'Compare original references, spaces and dates; preserve estate events','url'=>admin_url('admin.php?page=mathlin-google-calendar&sync_view=review'));
        foreach($items as&$item){
            $item['owner_role']=in_array($item['kind'],array('email','scheduler'),true)?'site_administrator':'booking_manager';
            $item['age_seconds']=null;
            if(!empty($item['since'])){try{$since=is_numeric($item['since'])?(int)$item['since']:(new DateTimeImmutable($item['since'],wp_timezone()))->getTimestamp();$item['age_seconds']=max(0,time()-$since);}catch(Throwable $e){$issues[]='An attention-item date could not be interpreted.';}}
        }unset($item);
        return array('checked_at'=>gmdate(DATE_RFC3339),'per_category_limit'=>$limit,'items'=>$items,'partial_failures'=>$issues,'read_only'=>true);
    }
    public static function render(){
        if(!current_user_can('mbs_manage_bookings')&&!current_user_can('manage_options'))return;
        $result=self::summary(5);
        if(!$result['items']&&!$result['partial_failures'])return;
        $items=$result['items'];$total=count($items);$visible=3;
        $row=function($item){
            $link_attr = $item['kind'] === 'email' ? ' class="mbs-attention__link mbs-open-email-diagnostics" data-tab="transport"' : ' class="mbs-attention__link"';
            return '<li class="mbs-attention__item"><span class="mbs-attention__ref">'.esc_html($item['ref']).'</span><span class="mbs-attention__finding">'.esc_html($item['finding']).'</span><a href="'.esc_url($item['url']).'"'.$link_attr.'>'.esc_html($item['action']).'</a></li>';
        };
        echo '<section class="mbs-attention" aria-labelledby="mbs-attention-title"><div class="mbs-attention__head"><h2 id="mbs-attention-title">Needs attention</h2>';
        if($total)echo '<span class="mbs-attention__count">'.(int)$total.'</span>';
        echo '<span class="mbs-attention__note">Read-only. Opening a link changes nothing.</span></div>';
        foreach($result['partial_failures']as$issue)echo '<p class="mbs-attention__issue" role="alert">'.esc_html($issue).'</p>';
        if($total){
            echo '<ul class="mbs-attention__list" aria-label="Outstanding booking actions">';
            foreach(array_slice($items,0,$visible)as$item)echo $row($item);
            echo '</ul>';
            if($total>$visible){
                echo '<details class="mbs-attention__more"><summary>Show '.(int)($total-$visible).' more</summary><ul class="mbs-attention__list" aria-label="More outstanding booking actions">';
                foreach(array_slice($items,$visible)as$item)echo $row($item);
                echo '</ul></details>';
            }
        }
        echo '</section>';
    }
}
