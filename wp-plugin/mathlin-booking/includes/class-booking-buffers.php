<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-booking-intervals.php';

/** Optional physical setup/clear-down occupancy; never changes billed times. */
class MBS_Booking_Buffers {
    public static function enabled() { return (bool) get_option( 'mbs_buffers_enabled', false ); }
    public static function minutes( $space ) {
        $rules = (array) get_option( 'mbs_resource_buffers', array() );
        $before=0; $after=0;
        foreach ( MBS_Space_Selection::members( $space ) as $member ) {
            $before=max($before,max(0,min(240,(int)($rules[$member]['before']??0))));
            $after=max($after,max(0,min(240,(int)($rules[$member]['after']??0))));
        }
        return array($before,$after);
    }
    public static function expand( $interval, $space ) {
        list($before,$after)=self::minutes($space);
        return array('start'=>$interval['start']->modify('-'.$before.' minutes'),'end'=>$interval['end']->modify('+'.$after.' minutes'));
    }
    public static function conflicts( $space, $date, $start, $end, $all_day, $exclude ) {
        global $wpdb;
        try {
            $day=new DateTimeImmutable($date,wp_timezone());
            $a=$all_day?$day:new DateTimeImmutable($date.' '.$start,wp_timezone());
            $z=$all_day?$day->modify('+1 day'):new DateTimeImmutable($date.' '.$end,wp_timezone());
            if($z<=$a)return self::unknown($space,$date);
            $want=self::expand(array('start'=>$a,'end'=>$z),$space);
            // At most four hours on either side, including adjacent-day rows.
            $from=$want['start']->modify('-1 day')->format('Y-m-d');$to=$want['end']->modify('+1 day')->format('Y-m-d');
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_TABLE." WHERE booking_date<=%s AND COALESCE(NULLIF(booking_date_end,''),booking_date)>=%s AND status NOT IN ('cancelled','archived') AND ref<>%s ORDER BY id ASC",$to,$from,$exclude));
            if(!is_array($rows)||$wpdb->last_error)return self::unknown($space,$date);
            $conflicts=array();
            foreach($rows as$b){
                if(!MBS_Space_Selection::overlaps($space,$b->space))continue;
                foreach(MBS_Booking_Intervals::intervals($b)as$interval){$occupied=self::expand($interval,$b->space);if($want['start']<$occupied['end']&&$want['end']>$occupied['start']){$conflicts[$b->ref]=$b;break;}}
            }
            $last=$want['end']->modify('-1 second')->format('Y-m-d');
            for($d=$want['start']->setTime(0,0);$d->format('Y-m-d')<=$last;$d=$d->modify('+1 day'))if(MBS_Blocked_Dates::is_blocked($d->format('Y-m-d'),$space))return array_merge(array_values($conflicts),self::unknown($space,$date,'Setup or clear-down overlaps a blocked date'));
            return array_values($conflicts);
        } catch(Throwable $e){return self::unknown($space,$date);}
    }
    private static function unknown($space,$date,$name='Buffered availability could not be verified'){return array((object)array('space'=>$space,'booking_date'=>$date,'all_day'=>1,'name'=>$name,'ref'=>''));}
}
