<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-booking-intervals.php';

/** Physical occupancy only. Reports never alter prices or allocations. */
class MBS_Usage_Report {
    public static function report( $from, $to ) {
        global $wpdb;
        $rows = array(); $last = 0;
        do {
            $page = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE id>%d AND booking_date<=%s AND COALESCE(NULLIF(booking_date_end,''),booking_date)>=%s AND status IN ('confirmed','deposit_paid','paid') ORDER BY id ASC LIMIT 200", $last, $to, $from ) );
            if ( ! is_array( $page ) || $wpdb->last_error ) throw new RuntimeException( 'Occupancy could not be read completely.' );
            foreach ( $page as $row ) { $rows[] = $row; $last = (int) $row->id; }
        } while ( count( $page ) === 200 );
        return self::calculate( $rows, MBS_Bookings::get_spaces(), $from, $to );
    }
    public static function calculate( $bookings, $spaces, $from, $to ) {
        $zone = wp_timezone(); $first = new DateTimeImmutable( $from, $zone ); $last = new DateTimeImmutable( $to, $zone );
        if ( $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to || $last < $first || $first->diff($last)->days > 3660 ) throw new InvalidArgumentException( 'Choose a valid reporting period of ten years or less.' );
        $open = (int) get_option( 'mbs_report_open_hour', MBS_Campsite_Policy::enabled() ? 0 : 8 );
        $close = (int) get_option( 'mbs_report_close_hour', MBS_Campsite_Policy::enabled() ? 24 : 20 );
        if ( $open < 0 || $close > 24 || $close <= $open ) throw new RuntimeException( 'Reporting opening hours are invalid.' );
        $available=0; $days=0;
        for($day=$first;$day<=$last;$day=$day->modify('+1 day')){$available+=(self::hour($day,$close)->getTimestamp()-self::hour($day,$open)->getTimestamp())/3600;$days++;}
        $buckets=array();$refs=array();$attendees=array();$invalid=array();
        foreach($bookings as$booking){
            if(!in_array($booking->status,array('confirmed','deposit_paid','paid'),true))continue;
            try{$intervals=MBS_Booking_Intervals::intervals($booking);}catch(Throwable $e){$invalid[]=$booking->ref;continue;}
            $effective=array();
            foreach(MBS_Space_Selection::members($booking->space)as$member){
                if(!isset($spaces[$member])){$invalid[]=$booking->ref;continue;}
                $effective[$member]=true;
                // Expand descendants of the actual selection, not of ancestors:
                // booking one child must never occupy its sibling.
                foreach($spaces as$name=>$info){$cursor=$name;$seen=array();while(isset($spaces[$cursor])&&!isset($seen[$cursor])){$seen[$cursor]=true;$parent=$spaces[$cursor]['parent']??'';if($parent===$member){$effective[$name]=true;break;}$cursor=$parent;}}
                $cursor=$member;$seen=array();while(isset($spaces[$cursor])&&!isset($seen[$cursor])){$seen[$cursor]=true;$parent=$spaces[$cursor]['parent']??'';if(!$parent)break;$effective[$parent]=true;$cursor=$parent;}
            }
            foreach($intervals as$interval){
              $interval_first=$interval['start']->setTime(0,0);if($interval_first<$first)$interval_first=$first;
              $interval_last=$interval['end']->modify('-1 second')->setTime(0,0);if($interval_last>$last)$interval_last=$last;
              for($day=$interval_first;$day<=$interval_last;$day=$day->modify('+1 day')){
                $a=max($interval['start']->getTimestamp(),self::hour($day,$open)->getTimestamp());$z=min($interval['end']->getTimestamp(),self::hour($day,$close)->getTimestamp());if($z<=$a)continue;
                foreach($effective as$name=>$unused){$date=$day->format('Y-m-d');$buckets[$name][$date][]=array($a,$z);$refs[$name][$booking->ref]=true;$attendees[$name][$booking->ref]=(int)$booking->attendees;}
              }
            }
        }
        $out=array();
        foreach($spaces as$name=>$info){$hours=0;foreach($buckets[$name]??array()as$periods){usort($periods,static function($a,$b){return$a[0]<=>$b[0];});$a=null;$z=null;foreach($periods as$period){if($a===null){list($a,$z)=$period;}elseif($period[0]>$z){$hours+=($z-$a)/3600;list($a,$z)=$period;}else{$z=max($z,$period[1]);}}if($a!==null)$hours+=($z-$a)/3600;}
            $out[]=array('space'=>$name,'hours'=>round($hours,2),'total'=>round($available,2),'percent'=>$available?round(100*$hours/$available,1):0,'resource_days'=>count($buckets[$name]??array()),'reservations'=>count($refs[$name]??array()),'attendees_estimate'=>array_sum($attendees[$name]??array()));
        }
        return array('from'=>$from,'to'=>$to,'opening_hour'=>$open,'closing_hour'=>$close,'days'=>$days,'rows'=>$out,'invalid_refs'=>array_values(array_unique($invalid)));
    }
    private static function hour($day,$hour){return$hour===24?$day->modify('+1 day')->setTime(0,0):$day->setTime($hour,0);}
}
