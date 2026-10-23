<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Private, payload-free diagnostics. Never reports a failed read as an empty success. */
class MBS_Integration_Health {
    public function init() {
        add_action( 'admin_menu', function() { add_submenu_page( 'mathlin-booking', 'Integration health', 'Integration health', 'manage_options', 'mathlin-health', array( $this, 'render' ) ); } );
        add_action( 'rest_api_init', function() {
            register_rest_route( 'mathlin/v1', '/admin/health', array( 'methods'=>'GET', 'permission_callback'=>function() { return current_user_can( 'manage_options' ) || current_user_can( 'mbs_read_bookings' ); }, 'callback'=>function() { return rest_ensure_response( self::summary() ); } ) );
        } );
        add_filter( 'rest_post_dispatch', array( $this, 'record_failure' ), 10, 3 );
        add_action( 'admin_post_mbs_health_maintenance', array( $this, 'maintenance' ) );
    }
    public static function summary() {
        $cron = array();
        foreach ( array( 'mbs_daily_access_details','mbs_daily_reminders','mbs_daily_payment_chase','mbs_daily_auto_archive','mbs_google_calendar_tick' ) as $hook ) $cron[$hook] = wp_next_scheduled( $hook ) ?: null;
        $s = MBS_Google_Calendar::status();
        $lock = get_option( 'mbs_gcal_lock', array() );
        return array( 'plugin_version'=>MBS_VERSION, 'php_version'=>PHP_VERSION, 'wordpress_version'=>get_bloginfo( 'version' ), 'woocommerce_version'=>defined('WC_VERSION')?WC_VERSION:null,
            'database_schema_current'=>MBS_Database::migration_is_current(), 'payment_features'=>MBS_Payment_Features::state(), 'campsite'=>MBS_Campsite_Policy::enabled(), 'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON, 'next_runs'=>$cron,
            'email_queue'=>MBS_Email_Queue::get_stats(), 'mail_delivery_note'=>'Accepted means the configured mail transport accepted the email, not confirmed inbox delivery.',
            'google'=>array_intersect_key( $s, array_flip( array('connected','enabled','managed_updates','last_attempt','last_success','last_result') ) ),
            'google_lock'=>array( 'present'=>!empty($lock), 'age_seconds'=>isset($lock['at'])?max(0,time()-(int)$lock['at']):null, 'legacy'=>!empty($lock)&&($lock['engine']??'')!=='connection-v1' ),
            'recent_server_errors'=>get_option( 'mbs_recent_server_errors', array() ), 'host_checks_required'=>array('PHP/web-server logs for HTTP500 correlation','Actual system-cron execution and mail-provider delivery/bounce logs') );
    }
    public function record_failure( $response, $server, $request ) {
        if ( strpos( $request->get_route(), '/mathlin/v1/' ) !== 0 || ! is_object($response) || !method_exists($response,'get_status') || $response->get_status()<500 ) return $response;
        $id = wp_generate_uuid4();
        $response->header( 'X-MGF-Error-ID', $id );
        $history = (array) get_option( 'mbs_recent_server_errors', array() );
        $history[] = array('id'=>$id,'at'=>gmdate(DATE_RFC3339),'status'=>$response->get_status());
        update_option( 'mbs_recent_server_errors', array_slice($history,-20), false );
        error_log( '[MGF Venue] Server error correlation ' . $id . '; inspect hosting logs. Request payloads and credentials omitted.' );
        return $response;
    }
    public function render() {
        if ( ! current_user_can('manage_options') ) return;
        $state=self::summary();
        echo '<div class="wrap"><h1>MGF Venue — Integration health</h1><p>This is a read-only snapshot. Review needed does not mean sync is paused. Mail transport acceptance does not prove inbox delivery.</p><table class="widefat striped" style="max-width:1000px"><tbody>';
        $rows=array('Installed plugin'=>$state['plugin_version'],'Database schema'=>$state['database_schema_current']?'Ready':'Needs administrator review','Booking profile'=>$state['campsite']?'Free campsite':'Standard venue','Payments'=>$state['payment_features']['payments']['enabled']?'Enabled':'Disabled','Email queue'=>sprintf('%d pending; %d failed; %d accepted by transport',$state['email_queue']['pending']??0,$state['email_queue']['failed']??0,$state['email_queue']['sent']??0),'Google connection'=>$state['google']['connected']?($state['google']['enabled']?'Connected — sync enabled':'Connected — sync paused'):'Not connected','Google last completed comparison'=>$state['google']['last_success']?:'Not yet run','Google result'=>$state['google']['last_result'],'Host cron'=>$state['cron_disabled']?'WordPress-triggered cron disabled; verify the host scheduler':'WordPress-triggered cron enabled; verify host scheduling if precise timing is required');
        foreach($rows as$label=>$value)echo '<tr><th style="width:250px">'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('admin.php?page=mathlin-google-calendar')).'">Review calendar links</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=mathlin-emails')).'">Review email templates</a></p><details><summary>Technical diagnostic details</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html(wp_json_encode($state,JSON_PRETTY_PRINT)).'</pre></details>';
        $lock = get_option('mbs_gcal_lock',array());
        if ( $lock && ($lock['engine']??'')!=='connection-v1' ) {
            echo '<h2>Legacy calendar lock recovery</h2><p>Check hosting workers before recovery. This never enables sync or edits Google events.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mbs_health_maintenance');
            echo '<input type="hidden" name="action" value="mbs_health_maintenance"><input type="hidden" name="expected" value="' . esc_attr(hash('sha256',wp_json_encode($lock))) . '"><label><input type="checkbox" name="confirm" value="yes" required> I checked that no legacy worker is running.</label><p><button class="button">Recover checked legacy lock</button></p></form>';
        }
        $quotes=MBS_Email_Templates::quote_repair_preview();
        if($quotes['before']){
            echo '<h2>Review escaped apostrophes in saved email templates</h2><p>Affected templates: '.esc_html(implode(', ',array_keys($quotes['before']))).'. Review these in Email Templates first. The repair removes only backslash-apostrophe sequences and saves a private before-image; issued invoices are untouched.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mbs_health_maintenance');
            echo '<input type="hidden" name="action" value="mbs_health_maintenance"><input type="hidden" name="operation" value="repair_template_quotes"><input type="hidden" name="expected" value="'.esc_attr($quotes['fingerprint']).'"><label><input type="checkbox" name="confirm" value="yes" required> I reviewed these template changes.</label><p><button class="button">Repair reviewed template apostrophes</button></p></form>';
        }
        echo '</div>';
    }
    public function maintenance() {
        if(!current_user_can('manage_options')) wp_die('Administrator required.');
        check_admin_referer('mbs_health_maintenance');
        if(($_POST['confirm']??'')!=='yes') wp_die('Explicit confirmation required.');
        $expected=sanitize_text_field(wp_unslash($_POST['expected']??''));
        if(!in_array($_POST['operation']??'',array('','repair_template_quotes'),true))wp_die('Unknown maintenance operation.');
        $result=MBS_Space_Selection::locked(static function()use($expected){return ($_POST['operation']??'')==='repair_template_quotes'?MBS_Email_Templates::repair_template_quotes($expected):MBS_Google_Calendar::recover_legacy_lock($expected);});
        if(is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        wp_safe_redirect(admin_url('admin.php?page=mathlin-health')); exit;
    }
}
