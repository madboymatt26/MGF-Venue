<?php
global $wpdb;
$mode = isset($args[0]) ? sanitize_key($args[0]) : '';
$barrier = $wpdb->prefix . 'mbs_test_migration_barrier';
$token = 'mbs_migration_' . substr(hash('sha256',(defined('DB_NAME')?DB_NAME:'wordpress').':'.$wpdb->prefix),0,32);

if($mode==='holder'){
    add_action('mbs_migration_lock_acquired',static function()use($wpdb,$barrier){
        $wpdb->query("UPDATE {$barrier} SET acquired=1 WHERE id=1");
        $deadline=microtime(true)+30;
        do{if((int)$wpdb->get_var("SELECT release_holder FROM {$barrier} WHERE id=1")===1)return;usleep(50000);}while(microtime(true)<$deadline);
        throw new RuntimeException('Migration holder barrier timed out.');
    });
    $result=MBS_Database::create_tables();
    if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
    echo "OK: migration holder completed after overlap.\n";
}elseif($mode==='contender'){
    $deadline=microtime(true)+30;
    do{if((int)$wpdb->get_var("SELECT acquired FROM {$barrier} WHERE id=1")===1)break;usleep(50000);}while(microtime(true)<$deadline);
    $result=MBS_Database::create_tables();
    if(!is_wp_error($result)||$result->get_error_code()!=='migration_locked')throw new RuntimeException('Overlapping migration worker was not rejected by the advisory lock.');
    $foreign_release=(int)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$token));
    if($foreign_release!==0)throw new RuntimeException('A non-owner connection released the migration lock.');
    $wpdb->query("UPDATE {$barrier} SET release_holder=1 WHERE id=1");
    echo "OK: overlapping worker was rejected and could not release the owner lock.\n";
}elseif($mode==='abandoned-owner'){
    if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$token))!==1)throw new RuntimeException('Could not acquire disposable abandoned migration lock.');
    update_option('mbs_migration_lock',array('owner'=>$token,'connection'=>(int)$wpdb->get_var('SELECT CONNECTION_ID()'),'acquired_at'=>current_time('mysql')),false);
    echo "OK: connection exits without explicit release to emulate an abandoned lock.\n";
}elseif($mode==='recover'){
    $result=MBS_Database::create_tables();
    if(is_wp_error($result)||get_option('mbs_migration_lock',null)!==null)throw new RuntimeException('Migration did not take over after abandoned connection cleanup.');
    echo "OK: abandoned connection lock was recovered and stale diagnostic cleared.\n";
}else throw new RuntimeException('Unknown migration worker mode.');
