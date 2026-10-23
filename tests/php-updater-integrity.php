<?php
define('ABSPATH',__DIR__);define('MBS_PLUGIN_DIR',__DIR__.'/fixture/');define('MBS_VERSION','3.38.1');
class WP_Error{function __construct($code,$message){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function plugin_basename($path){return 'mathlin-booking/mathlin-booking.php';}
function get_option($key,$default=false){return $default;}
function wp_remote_get($url,$args){return array('code'=>200,'body'=>json_encode($GLOBALS['release']));}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function download_url($url){return $GLOBALS['fixture_file'];}
function wp_delete_file($file){if($file!==$GLOBALS['fixture_file'])throw new RuntimeException('Unexpected file deletion');unlink($file);}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-updater.php';
$fixture_file=tempnam(sys_get_temp_dir(),'mgf_integrity_');
$package='https://github.com/madboymatt26/MGF-Venue/releases/download/v3.39.0/MGF-Venue-3.39.0.zip';
$args=array('plugin'=>'mathlin-booking/mathlin-booking.php');
try{
    file_put_contents($fixture_file,'synthetic release bytes');
    $release=array('tag_name'=>'v3.39.0','assets'=>array(array('name'=>'unrelated.zip','browser_download_url'=>'https://example.invalid/not-the-plugin.zip'),array('name'=>'MGF-Venue-3.39.0.zip','browser_download_url'=>$package,'digest'=>'sha256:'.hash_file('sha256',$fixture_file))),'zipball_url'=>'https://api.github.com/fixture-source');
    $updater=new MBS_Updater();
    if($updater->verify_release_download(false,$package,null,$args)!==$fixture_file)throw new RuntimeException('Valid named release rejected');
    file_put_contents($fixture_file,'corrupted synthetic bytes');
    if(!is_wp_error($updater->verify_release_download(false,$package,null,$args))||file_exists($fixture_file))throw new RuntimeException('Corrupt bytes were not rejected/removed');
    if($updater->verify_release_download(false,'/local/manual-upload.zip',null,$args)!==false)throw new RuntimeException('Manual upload workflow intercepted');
    unset($release['assets'][1]['digest']);$updater=new MBS_Updater();
    if(!is_wp_error($updater->verify_release_download(false,$package,null,$args)))throw new RuntimeException('Missing published digest accepted');
    echo "UPDATER_INTEGRITY_OK (exact asset, valid digest, corruption, missing digest, manual compatibility)\n";
}finally{if(file_exists($fixture_file))unlink($fixture_file);}
