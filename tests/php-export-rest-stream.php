<?php
/** MCP keeps direct CSV; retained browser downloads remain user/nonce bound. */
define('ABSPATH', __DIR__);
function nocache_headers() {}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-accounting-export.php';
$csv="Reference,Amount\r\nEXAMPLE,20.00\r\n";
if(in_array('--child',$argv,true)){
    define('REST_REQUEST',true);
    MBS_Accounting_Export::serve_rest_csv($csv,'bookings');
    throw new RuntimeException('REST streaming must terminate before browser redirect.');
}
// Normal browser requests must return to the retained-snapshot path.
ob_start();MBS_Accounting_Export::serve_rest_csv($csv,'bookings');$browser=ob_get_clean();
if($browser!=='')throw new RuntimeException('Browser request streamed before snapshot creation.');
$ini=php_ini_loaded_file();
$command=escapeshellarg(PHP_BINARY).($ini?' -c '.escapeshellarg($ini):'').' '.escapeshellarg(__FILE__).' --child';
$pipes=array();$process=proc_open($command,array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
if(!is_resource($process))throw new RuntimeException('REST subprocess unavailable.');
$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
if($status!==0||$body!==$csv||$errors!=='')throw new RuntimeException('REST export must preserve exact CSV without redirects, HTML or warnings: '.$errors);
echo "PASS direct MCP CSV and retained browser separation\n";
