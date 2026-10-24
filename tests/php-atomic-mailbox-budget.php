<?php
define('ABSPATH',__DIR__);$options=array();
function add_option($key,$value,$unused='',$autoload=false){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
class BudgetDB{public $options='options';public $fail=false;public function prepare($sql,...$args){return$args;}public function query($args){if($this->fail)return false;list($key,$limit)=$args;if((int)$GLOBALS['options'][$key]>=$limit)return 0;$GLOBALS['options'][$key]++;return 1;}}
$wpdb=new BudgetDB();require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-hirer-identity.php';
for($i=0;$i<10;$i++)if(MBS_Hirer_Identity::throttle('mailbox','fixture@example.test',3)!==($i<3))throw new RuntimeException('Incorrect conditional increment budget');
$wpdb->fail=true;if(MBS_Hirer_Identity::throttle('other','fixture@example.test'))throw new RuntimeException('Database failure must deny, not permit');
echo "PASS conditional database budget and fail-closed throttle\n";
