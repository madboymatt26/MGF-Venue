<?php
define('ABSPATH',__DIR__);
class WP_REST_Request{private $method;private $route;function __construct($method,$route){$this->method=$method;$this->route=$route;}function get_method(){return $this->method;}function get_route(){return $this->route;}}
class WP_Error{function __construct($code,$message,$data=array()){}}
class MBS_Campsite_Policy{static function enabled(){return true;}static function can_book(){return false;}}
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'],true);}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-rest-api.php';
$caps=array('mbs_read_bookings');$api=new MBS_Rest_API();$checks=0;
function check($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
foreach(array('/admin/bookings','/admin/bookings/MBS-TEST','/admin/bookings/MBS-TEST/audit','/admin/availability','/bookings/today','/bookings/upcoming')as$path)check($api->booking_manager_permission(new WP_REST_Request('GET','/mathlin/v1'.$path)),'allowed reader route '.$path);
foreach(array('POST','PATCH','DELETE')as$method)check(!$api->booking_manager_permission(new WP_REST_Request($method,'/mathlin/v1/admin/bookings')),'reader write denied');
foreach(array('/admin/bookings/MBS-TEST/status','/admin/invoices/INV-TEST/payment-url','/admin/actions/save_settings','/admin/future-unreviewed-route')as$path)check(!$api->booking_manager_permission(new WP_REST_Request('GET','/mathlin/v1'.$path)),'unreviewed/credential route denied');
check($api->campsite_public_permission(null,null,new WP_REST_Request('GET','/mathlin/v1/bookings/today'))===null,'reader HA access does not need a booking role');
check($api->campsite_public_permission(null,null,new WP_REST_Request('GET','/mathlin/v1/bookings/ical')) instanceof WP_Error,'reader does not gain campsite portal/feed eligibility');
$caps=array();check(!$api->booking_manager_permission(new WP_REST_Request('GET','/mathlin/v1/bookings/today')),'anonymous denied');
$caps=array('mbs_manage_bookings');check($api->booking_manager_permission(new WP_REST_Request('POST','/mathlin/v1/admin/bookings/create')),'manager operation permission retained');
echo 'READER_PERMISSIONS_OK ('.$checks.' actual callback checks)'.PHP_EOL;
