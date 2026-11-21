<?php
/** Synthetic loopback fixtures only, used by actual HTTP/parser/browser acceptance. */
if(!defined('WP_CLI')||!WP_CLI||!in_array(parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1','wordpress'),true))throw new RuntimeException('Disposable loopback required.');
global $wpdb;
update_option('timezone_string','Europe/London');update_option('mbs_campsite_mode',0);update_option('mbs_ha_webhook_url','');update_option('mbs_google_calendar_enabled',0);
$tag='ICAL'.wp_rand(10000,99999);$refs=array();
$make=static function($key,$changes=array())use(&$refs,$tag,$wpdb){$ref=$tag.'-'.$key;$row=array_merge(array('ref'=>$ref,'name'=>'Hidden personal name','email'=>'secret-calendar@example.invalid','phone'=>'0123456789','space'=>'Main Hall','booking_date'=>'2098-06-14','booking_date_end'=>'2098-06-14','start_time'=>'10:00','end_time'=>'12:00','amount'=>'999.12','amount_paid'=>0,'status'=>'confirmed','attendees'=>30,'purpose'=>$tag.' public, event; Unicode café','notes'=>'PRIVATE-SUBSCRIPTION-NOTES','is_public'=>1),$changes);if(!$wpdb->insert($wpdb->prefix.MBS_TABLE,$row))throw new RuntimeException($wpdb->last_error);$refs[$key]=$ref;};
$make('PUBLIC');$make('PRIVATE',array('purpose'=>'PRIVATE-SUBSCRIPTION-PURPOSE','is_public'=>0,'start_time'=>'13:00','end_time'=>'14:00'));
$make('WINTER',array('booking_date'=>'2098-01-14','booking_date_end'=>'2098-01-14','purpose'=>$tag.' winter'));
$make('REPEAT',array('booking_date'=>'2098-06-15','booking_date_end'=>'2098-06-17','purpose'=>$tag.' repeat'));
$make('NIGHT',array('booking_date'=>'2098-06-18','booking_date_end'=>'2098-06-19','start_time'=>'22:00','end_time'=>'02:00','purpose'=>$tag.' overnight'));
$make('ALLDAY',array('booking_date'=>'2098-06-20','booking_date_end'=>'2098-06-20','all_day'=>1,'purpose'=>$tag.' all day'));
$make('CANCELLED',array('status'=>'cancelled','purpose'=>'CANCELLED-SUBSCRIPTION-PURPOSE'));
$id=wp_insert_post(array('post_title'=>'Synthetic Whats On','post_status'=>'publish','post_type'=>'page','post_content'=>'[mathlin_calendar]'));
$fixture=array('tag'=>$tag,'refs'=>$refs,'page_url'=>get_permalink($id),'feed_url'=>rest_url('mathlin/v1/bookings/ical'));
update_option('mbs_calendar_subscription_fixture',$fixture);echo wp_json_encode($fixture);
