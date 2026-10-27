<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-booking-intervals.php';

/** Physical occupancy only. Reports never alter prices or allocations. */
class MBS_Usage_Report {
    public static function report( $from, $to ) {
        global $wpdb;
        $rows = array(); $last = 0;
        $query_from = (new DateTimeImmutable($from,wp_timezone()))->modify('-1 day')->format('Y-m-d');
        $query_to = (new DateTimeImmutable($to,wp_timezone()))->modify('+1 day')->format('Y-m-d');
        do {
            $page = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE id>%d AND booking_date<=%s AND COALESCE(NULLIF(booking_date_end,''),booking_date)>=%s AND status IN ('confirmed','deposit_paid','paid') ORDER BY id ASC LIMIT 200", $last, $query_to, $query_from ) );
            if ( ! is_array( $page ) || $wpdb->last_error ) throw new RuntimeException( 'Occupancy could not be read completely.' );
            foreach ( $page as $row ) { $rows[] = $row; $last = (int) $row->id; }
        } while ( count( $page ) === 200 );
        $blocks = MBS_Blocked_Dates::get_for_range($from,$to);
        if(!is_array($blocks)||$wpdb->last_error)throw new RuntimeException('Reporting closures could not be read completely.');
        return self::calculate( $rows, MBS_Bookings::get_spaces(), $from, $to, $blocks );
    }
    public static function calculate( $bookings, $spaces, $from, $to, $blocks = array() ) {
        $zone = wp_timezone(); $first = new DateTimeImmutable( $from, $zone ); $last = new DateTimeImmutable( $to, $zone );
        if ( $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to || $last < $first || $first->diff($last)->days > 3660 ) throw new InvalidArgumentException( 'Choose a valid reporting period of ten years or less.' );
        $open = (int) get_option( 'mbs_report_open_hour', MBS_Campsite_Policy::enabled() ? 0 : 8 );
        $close = (int) get_option( 'mbs_report_close_hour', MBS_Campsite_Policy::enabled() ? 24 : 20 );
        if ( $open < 0 || $close > 24 || $close <= $open ) throw new RuntimeException( 'Reporting opening hours are invalid.' );
        $available=0; $days=0; $day_hours=array();
        for($day=$first;$day<=$last;$day=$day->modify('+1 day')){$h=(self::hour($day,$close)->getTimestamp()-self::hour($day,$open)->getTimestamp())/3600;$available+=$h;$day_hours[$day->format('Y-m-d')]=$h;$days++;}
        $buckets=array();$physical_buckets=array();$refs=array();$attendees=array();$invalid=array();
        $buffers=class_exists('MBS_Booking_Buffers')&&MBS_Booking_Buffers::enabled();
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
              $physical=$buffers?MBS_Booking_Buffers::expand($interval,$booking->space):$interval;
              $interval_first=$physical['start']->setTime(0,0);if($interval_first<$first)$interval_first=$first;
              $interval_last=$physical['end']->modify('-1 second')->setTime(0,0);if($interval_last>$last)$interval_last=$last;
              for($day=$interval_first;$day<=$interval_last;$day=$day->modify('+1 day')){
                $a=max($interval['start']->getTimestamp(),self::hour($day,$open)->getTimestamp());$z=min($interval['end']->getTimestamp(),self::hour($day,$close)->getTimestamp());
                $pa=max($physical['start']->getTimestamp(),self::hour($day,$open)->getTimestamp());$pz=min($physical['end']->getTimestamp(),self::hour($day,$close)->getTimestamp());
                foreach($effective as$name=>$unused){$date=$day->format('Y-m-d');if($pz>$pa)$physical_buckets[$name][$date][]=array($pa,$pz);if($z>$a){$buckets[$name][$date][]=array($a,$z);$refs[$name][$booking->ref]=true;$attendees[$name][$booking->ref]=(int)$booking->attendees;}}
              }
            }
        }
        $out=array();
        foreach($spaces as$name=>$info){
            $hours=0;$physical_hours=0;$closed_hours=0;$closed_overlap=0;
            foreach($day_hours as$date=>$capacity){
                $service=self::union_hours($buckets[$name][$date]??array());
                $physical=self::union_hours($physical_buckets[$name][$date]??array());
                $closed=false;foreach($blocks as$block)if($block->date_from<=$date&&$block->date_to>=$date&&($block->space===''||MBS_Space_Selection::overlaps($name,$block->space))){$closed=true;break;}
                if($closed){$closed_hours+=$capacity;$closed_overlap+=$physical;continue;}
                $hours+=$service;$physical_hours+=$physical;
            }
            $usable=max(0,$available-$closed_hours);
            $out[]=array('space'=>$name,'hours'=>round($hours,2),'buffer_hours'=>round(max(0,$physical_hours-$hours),2),'physical_hours'=>round($physical_hours,2),'closed_hours'=>round($closed_hours,2),'closed_overlap_hours'=>round($closed_overlap,2),'total'=>round($usable,2),'percent'=>$usable?round(100*$hours/$usable,1):0,'resource_days'=>count($buckets[$name]??array()),'reservations'=>count($refs[$name]??array()),'attendees_estimate'=>array_sum($attendees[$name]??array()));
        }
        return array('from'=>$from,'to'=>$to,'opening_hour'=>$open,'closing_hour'=>$close,'days'=>$days,'rows'=>$out,'invalid_refs'=>array_values(array_unique($invalid)));
    }
    private static function hour($day,$hour){return$hour===24?$day->modify('+1 day')->setTime(0,0):$day->setTime($hour,0);}
    private static function union_hours($periods){
        usort($periods,static function($a,$b){return$a[0]<=>$b[0];});$seconds=0;$a=null;$z=null;
        foreach($periods as$period){if($a===null){list($a,$z)=$period;}elseif($period[0]>$z){$seconds+=$z-$a;list($a,$z)=$period;}else{$z=max($z,$period[1]);}}
        if($a!==null)$seconds+=$z-$a;return$seconds/3600;
    }
}
