<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Single local-day events are recalculated across DST; overdue work is not skipped. */
class MBS_Local_Schedule {
    public static function register( $hook, $option, $default ) {
        $ensure = static function() use ( $hook, $option, $default ) { MBS_Local_Schedule::ensure( $hook, get_option( $option, $default ) ); };
        add_action( $hook, $ensure, PHP_INT_MAX );
        $ensure();
    }
    public static function next_timestamp( $clock, $now = null ) {
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) $clock ) ) $clock = '07:00';
        $now = $now ?: new DateTimeImmutable( 'now', wp_timezone() );
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $clock ) );
        $next = $now->setTime( $hour, $minute );
        if ( $next <= $now ) $next = $next->modify( '+1 day' );
        return $next->getTimestamp();
    }
    public static function ensure( $hook, $clock ) {
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) $clock ) ) $clock='07:00';
        $event = wp_get_scheduled_event( $hook );
        if ( $event && ! $event->schedule ) {
            if ( $event->timestamp <= time() ) return; // Never skip overdue work.
            $scheduled=(new DateTimeImmutable('@'.$event->timestamp))->setTimezone(wp_timezone());
            if ( $scheduled->format('H:i') === $clock ) return;
            wp_clear_scheduled_hook($hook); // Explicit time/timezone preference changed.
            wp_schedule_single_event(self::next_timestamp($clock),$hook);return;
        }
        $was_due = $event && $event->timestamp <= time();
        if ( $event ) wp_clear_scheduled_hook( $hook );
        wp_schedule_single_event( $was_due ? time() + 1 : self::next_timestamp( $clock ), $hook );
    }
}
