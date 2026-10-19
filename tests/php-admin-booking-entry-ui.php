<?php
require __DIR__.'/fixtures/admin-booking-entry-view.php';
function entry_assert($v,$label){if(!$v)throw new RuntimeException($label);}
$_GET['mode']='camp';ob_start();entry_fixture();$camp=ob_get_clean();
foreach(['Add booking','Estate reservation','Continuous stay','coordinator_contact','campsite_exception','Check dates and review','Review before saving','custom_fields[test]','entry-confirm'] as $text)entry_assert(strpos($camp,$text)!==false,'Camp field '.$text);
foreach(['name="pricing_tier"','name="kitchen"','name="address"','Internal Scout use','Paid recurring hires'] as $text)entry_assert(strpos($camp,$text)===false,'Camp hides paid feature '.$text);
$_GET['mode']='paid';ob_start();entry_fixture();$paid=ob_get_clean();
foreach(['name="pricing_tier"','name="kitchen"','name="address"','Internal Scout use','Paid recurring hires'] as $text)entry_assert(strpos($paid,$text)!==false,'Paid field '.$text);
entry_assert(strpos($paid,'Estate reservation')===false,'Estate option absent from paid venue');
entry_assert(!preg_match('/name="notify_hirer"[^>]*checked/',$camp),'Notification opt-in only');
echo "STAFF_BOOKING_ENTRY_UI_OK (21 checks)\n";
