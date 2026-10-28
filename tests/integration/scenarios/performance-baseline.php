<?php
/** Reproducible local benchmark, not a production load test. Fixtures rolled back. */
if(!defined('WP_CLI')||!WP_CLI||!in_array(strtolower((string)parse_url(home_url(),PHP_URL_HOST)),array('127.0.0.1','localhost','wordpress'),true))throw new RuntimeException('Disposable runtime required.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$spaces=get_option('mbs_spaces',null);$mode=get_option('mbs_campsite_mode',null);$results=array();
try{
    update_option('mbs_spaces',array('Performance Test'=>array('rate_hourly'=>0,'rate_daily'=>0,'capacity'=>100)));update_option('mbs_campsite_mode',0);
    foreach(array(300,3000,20000)as$count){
        if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Could not start disposable fixture.');
        $tag=substr(wp_generate_uuid4(),0,8);$origin=new DateTimeImmutable('2050-01-01',wp_timezone());
        for($offset=0;$offset<$count;$offset+=500){$values=array();$args=array();for($i=$offset;$i<min($count,$offset+500);$i++){
            $date=$origin->modify('+'.($i%2190).' days')->format('Y-m-d');$values[]="(%s,'confirmed','Synthetic','performance@example.invalid','Performance Test',%s,%s,'10:00:00','11:00:00',1,0,%s)";
            array_push($args,'PERF-'.$tag.'-'.$i,$date,$date,'PERF-SER-'.$tag.'-'.($i%50));
        }if($wpdb->query($wpdb->prepare("INSERT INTO {$table} (ref,status,name,email,space,booking_date,booking_date_end,start_time,end_time,scout_use,amount,series_id) VALUES ".implode(',',$values),$args))===false)throw new RuntimeException('Could not seed benchmark.');}
        $measure=static function($callback)use($wpdb){$q=$wpdb->num_queries;$start=hrtime(true);$value=$callback();return array('ms'=>round((hrtime(true)-$start)/1000000,3),'queries'=>$wpdb->num_queries-$q,'rows'=>count((array)$value));};
        $calendar=$measure(static function(){return MBS_Bookings::get_booked_dates(2050,1);});
        $refs=array();for($i=0;$i<50;$i++)$refs[]='PERF-SER-'.$tag.'-'.$i;
        $summaries=$measure(static function()use($refs){return MBS_Series::occurrence_summaries($refs);});
        $search=$measure(static function(){return MBS_Bookings::get_all(array('search'=>'PERF-','limit'=>100,'orderby'=>'booking_date','order'=>'ASC'));});
        $plan=$wpdb->get_results($wpdb->prepare("EXPLAIN SELECT id FROM {$table} WHERE series_id=%s",$refs[0]),ARRAY_A);
        if($wpdb->last_error||$summaries['queries']!==1||$summaries['rows']!==50||$calendar['rows']!==31)throw new RuntimeException('Benchmark correctness/query bound failed.');
        $results[]=array('synthetic_records'=>$count,'calendar'=>$calendar,'50_series_summary'=>$summaries,'search_first_100'=>$search,'series_index_plan'=>$plan);
        $wpdb->query('ROLLBACK');
    }
    echo "PERFORMANCE_BASELINE_OK\n".wp_json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
}finally{$wpdb->query('ROLLBACK');foreach(array('mbs_spaces'=>$spaces,'mbs_campsite_mode'=>$mode)as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}}
