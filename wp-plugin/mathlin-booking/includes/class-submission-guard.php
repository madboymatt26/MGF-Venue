<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Browser retry deduplication, separate from occupancy and payment idempotency. */
class MBS_Submission_Guard {
    private static $registry = '';
    private static $digest = '';
    public static function begin( $input ) {
        self::$registry = ''; self::$digest = '';
        $key = $input['submission_key'] ?? '';
        if ( $key === '' ) return true; // Older cached forms keep conflict protection.
        if ( ! is_string($key) || ! preg_match('/^[A-Za-z0-9-]{16,80}$/D',$key) ) return new WP_Error('invalid_submission_key','Refresh the form and try again.');
        unset($input['nonce'],$input['submission_key'],$input['action']);ksort($input);
        $encoded=wp_json_encode($input);
        if(!is_string($encoded)||strlen($encoded)>65536)return new WP_Error('submission_too_large','This booking request is too large.');
        self::$registry='mbs_submission_'.hash('sha256',$key.'|'.get_current_user_id());
        self::$digest=hash('sha256',$encoded);
        global $wpdb;
        $lock='mbs_submit_'.substr(hash('sha256',$wpdb->prefix.'|'.self::$registry),0,40);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('submission_busy','This request is already being saved. Wait briefly, then retry.');
        add_action('shutdown',static function()use($wpdb,$lock){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));});
        $saved=get_transient(self::$registry);
        if(is_array($saved)){
            if(!hash_equals($saved['digest'],self::$digest))return new WP_Error('submission_changed','This request key already saved another booking. Refresh for a new request.');
            $response=$saved['response'];$response['idempotent_replay']=true;return $response;
        }
        return true;
    }
    public static function remember( $response ) {
        if(self::$registry!=='')set_transient(self::$registry,array('digest'=>self::$digest,'response'=>$response),2*DAY_IN_SECONDS);
    }
}
