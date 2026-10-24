<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Private, payload-free diagnostics. Never reports a failed read as an empty success. */
class MBS_Integration_Health {
    public function init() {
        foreach ( self::job_hooks() as $hook ) self::observe_job( $hook );
        $this->register_interfaces();
    }
    private static function observe_job( $hook ) {
            $token = null;
            add_action( $hook, static function() use ( $hook, &$token ) {
                $token = wp_generate_uuid4();
                $prior = (array) get_option( 'mbs_job_' . $hook, array() );
                update_option( 'mbs_job_' . $hook, array( 'token' => $token, 'started_at' => time(), 'completed_at' => $prior['completed_at'] ?? null, 'duration_seconds' => $prior['duration_seconds'] ?? null, 'state' => 'running' ), false );
            }, -PHP_INT_MAX );
            add_action( $hook, static function() use ( $hook, &$token ) {
                $state = (array) get_option( 'mbs_job_' . $hook, array() );
                if ( ( $state['token'] ?? null ) !== $token ) return;
                $state['completed_at'] = time(); $state['duration_seconds'] = max( 0, time() - $state['started_at'] ); $state['state'] = 'completed';
                update_option( 'mbs_job_' . $hook, $state, false );
            }, PHP_INT_MAX );
    }
    private function register_interfaces() {
        add_action( 'admin_menu', function() { add_submenu_page( 'mathlin-booking', 'Integration health', 'Integration health', 'manage_options', 'mathlin-health', array( $this, 'render' ) ); } );
        add_action( 'rest_api_init', function() {
            register_rest_route( 'mathlin/v1', '/admin/health', array( 'methods'=>'GET', 'permission_callback'=>function() { return current_user_can( 'manage_options' ) || current_user_can( 'mbs_read_bookings' ); }, 'callback'=>function() { return rest_ensure_response( self::summary() ); } ) );
            register_rest_route( 'mathlin/v1', '/admin/attention', array( 'methods'=>'GET', 'permission_callback'=>function() { return current_user_can( 'manage_options' ) || current_user_can( 'mbs_read_bookings' ); }, 'callback'=>function() { return rest_ensure_response( MBS_Attention::summary() ); } ) );
        } );
        add_filter( 'rest_post_dispatch', array( $this, 'record_failure' ), 10, 3 );
        add_action( 'admin_post_mbs_health_maintenance', array( $this, 'maintenance' ) );
        add_action( 'admin_post_mbs_operational_settings', array( $this, 'save_operational_settings' ) );
    }
    private static function job_hooks() {
        return array( 'mbs_daily_access_details','mbs_daily_reminders','mbs_daily_payment_chase','mbs_daily_auto_archive','mbs_daily_feedback','mbs_google_calendar_tick','mbs_process_email_queue','mbs_ha_outbox' );
    }
    public static function job_status() {
        $out = array();
        foreach ( self::job_hooks() as $hook ) {
            $state = (array) get_option( 'mbs_job_' . $hook, array() );
            unset( $state['token'] );
            $state['next_run'] = wp_next_scheduled( $hook ) ?: null;
            $state['observed'] = ! empty( $state['started_at'] );
            $state['needs_attention'] = ( ( $state['state'] ?? '' ) === 'running' && time() - (int) $state['started_at'] > 900 ) || ( $state['next_run'] && $state['next_run'] < time() - 900 );
            $out[$hook] = $state;
        }
        return $out;
    }
    public static function payment_policy_warnings() {
        if ( ! MBS_Payment_Features::enabled() ) return array();
        $terms = (int) get_option( 'mbs_payment_days_required', 28 );
        $balance = (int) get_option( 'mbs_deposit_balance_days', 7 );
        return get_option( 'mbs_deposit_enabled', 1 ) && $terms !== $balance ? array( 'Hire terms require full payment ' . $terms . ' days before the booking; the deposit balance is set to ' . $balance . ' days before. Administrator policy review is required. Existing rules and invoices have not been changed.' ) : array();
    }
    public static function operational_settings() {
        return array( 'report_open_hour' => (int) get_option( 'mbs_report_open_hour', MBS_Campsite_Policy::enabled() ? 0 : 8 ), 'report_close_hour' => (int) get_option( 'mbs_report_close_hour', MBS_Campsite_Policy::enabled() ? 24 : 20 ), 'legacy_read_only' => (int) get_option( 'mbs_legacy_read_only', 0 ), 'buffers_enabled' => (int) get_option( 'mbs_buffers_enabled', 0 ), 'resource_buffers' => (array) get_option( 'mbs_resource_buffers', array() ) );
    }
    public function save_operational_settings() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator required.' );
        check_admin_referer( 'mbs_operational_settings' );
        if ( ! hash_equals( hash( 'sha256', wp_json_encode( self::operational_settings() ) ), (string) ( $_POST['expected'] ?? '' ) ) ) wp_die( 'Operational settings changed. Reload before saving.' );
        foreach ( array( 'report_open_hour','report_close_hour','legacy_read_only' ) as $key ) if ( ! isset( $_POST[$key] ) || ! is_scalar( $_POST[$key] ) || ! ctype_digit( (string) $_POST[$key] ) ) wp_die( 'Invalid operational settings.' );
        $open = (int) $_POST['report_open_hour']; $close = (int) $_POST['report_close_hour']; $legacy = (int) $_POST['legacy_read_only'];
        if ( $open > 23 || $close > 24 || $close <= $open || ! in_array( $legacy, array(0,1), true ) || ( $legacy && ! MBS_Campsite_Policy::enabled() ) ) wp_die( 'Invalid reporting hours or legacy compatibility profile.' );
        $enabled = (string) ( $_POST['buffers_enabled'] ?? '0' );
        if ( ! in_array( $enabled, array('0','1'), true ) || ( $enabled === '1' && ( $_POST['ack_buffers'] ?? '' ) !== '1' ) ) wp_die( 'Confirm the optional physical-occupancy buffer policy before enabling it.' );
        $rules = array(); $submitted = wp_unslash( $_POST['resource_buffers'] ?? array() );
        if ( ! is_array( $submitted ) || array_diff( array_keys($submitted), array_keys(MBS_Bookings::get_spaces()) ) ) wp_die( 'Unknown buffer resource.' );
        foreach ( MBS_Bookings::get_spaces() as $name => $space ) {
            if ( isset( $submitted[$name] ) && ! is_array( $submitted[$name] ) ) wp_die( 'Invalid buffer resource settings.' );
            $rules[$name] = array();
            foreach ( array('before','after') as $field ) {
                $value = $submitted[$name][$field] ?? '0';
                if ( ! is_scalar($value) || ! ctype_digit((string)$value) || (int)$value > 240 ) wp_die( 'Buffers must be whole minutes from 0 to 240.' );
                $rules[$name][$field] = (int)$value;
            }
        }
        update_option( 'mbs_report_open_hour', $open, false ); update_option( 'mbs_report_close_hour', $close, false ); update_option( 'mbs_legacy_read_only', $legacy, false );
        update_option( 'mbs_resource_buffers', $rules, false ); update_option( 'mbs_buffers_enabled', (int)$enabled, false );
        MBS_Audit_Log::log( 'SYSTEM', 'operational_settings', 'Reporting hours and explicit legacy compatibility settings saved by an administrator.' );
        wp_safe_redirect( admin_url( 'admin.php?page=mathlin-settings' ) ); exit;
    }
    public static function summary() {
        $cron = array();
        foreach ( self::job_hooks() as $hook ) $cron[$hook] = wp_next_scheduled( $hook ) ?: null;
        $s = MBS_Google_Calendar::status();
        $lock = get_option( 'mbs_gcal_lock', array() );
        return array( 'plugin_version'=>MBS_VERSION, 'php_version'=>PHP_VERSION, 'wordpress_version'=>get_bloginfo( 'version' ), 'woocommerce_version'=>defined('WC_VERSION')?WC_VERSION:null,
            'database_schema_current'=>MBS_Database::migration_is_current(), 'payment_features'=>MBS_Payment_Features::state(), 'campsite'=>MBS_Campsite_Policy::enabled(), 'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON, 'next_runs'=>$cron,
            'jobs'=>self::job_status(), 'payment_policy_warnings'=>self::payment_policy_warnings(),
            'email_queue'=>MBS_Email_Queue::get_stats(), 'home_assistant'=>MBS_HomeAssistant::health(), 'mail_delivery_note'=>'Accepted means the configured mail transport accepted the email, not confirmed inbox delivery.',
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
        foreach ( $state['payment_policy_warnings'] as $warning ) echo '<tr><th>Payment policy review</th><td>' . esc_html( $warning ) . '</td></tr>';
        foreach ( $state['jobs'] as $hook => $job ) if ( $job['needs_attention'] ) echo '<tr><th>Scheduled job needs attention</th><td>' . esc_html( $hook . ': overdue or still running. Inspect hosting logs before retrying.' ) . '</td></tr>';
        $rows=array('Installed plugin'=>$state['plugin_version'],'Database schema'=>$state['database_schema_current']?'Ready':'Needs administrator review','Booking profile'=>$state['campsite']?'Free campsite':'Standard venue','Payments'=>$state['payment_features']['payments']['enabled']?'Enabled':'Disabled','Email queue'=>sprintf('%d pending; %d failed; %d accepted by transport',$state['email_queue']['pending']??0,$state['email_queue']['failed']??0,$state['email_queue']['sent']??0),'Google connection'=>$state['google']['connected']?($state['google']['enabled']?'Connected — sync enabled':'Connected — sync paused'):'Not connected','Google last completed comparison'=>$state['google']['last_success']?:'Not yet run','Google result'=>$state['google']['last_result'],'Host cron'=>$state['cron_disabled']?'WordPress-triggered cron disabled; verify the host scheduler':'WordPress-triggered cron enabled; verify host scheduling if precise timing is required');
        foreach($rows as$label=>$value)echo '<tr><th style="width:250px">'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('admin.php?page=mathlin-google-calendar')).'">Review calendar links</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=mathlin-emails')).'">Review email templates</a></p><details><summary>Technical diagnostic details</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html(wp_json_encode($state,JSON_PRETTY_PRINT)).'</pre></details>';
        $lock = get_option('mbs_gcal_lock',array());
        if ( $lock && ($lock['engine']??'')!=='connection-v1' ) {
            echo '<h2>Legacy calendar lock recovery</h2><p>Check hosting workers before recovery. This never enables sync or edits Google events.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mbs_health_maintenance');
            echo '<input type="hidden" name="action" value="mbs_health_maintenance"><input type="hidden" name="expected" value="' . esc_attr(hash('sha256',wp_json_encode($lock))) . '"><label><input type="checkbox" name="confirm" value="yes" required> I checked that no legacy worker is running.</label><p><button class="button">Recover checked legacy lock</button></p></form>';
        }
        $failed_ha = MBS_HomeAssistant::failed_intents();
        if ( is_wp_error( $failed_ha ) ) echo '<p role="alert">' . esc_html( $failed_ha->get_error_message() ) . '</p>';
        else foreach ( $failed_ha as $intent ) {
            echo '<h2>Home Assistant delivery needs review: ' . esc_html( $intent['ref'] ) . '</h2><p>Check the endpoint before retrying. This queues the latest booking state for the normal worker; it does not send customer mail.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'mbs_health_maintenance' );
            echo '<input type="hidden" name="action" value="mbs_health_maintenance"><input type="hidden" name="operation" value="retry_ha"><input type="hidden" name="ref" value="' . esc_attr( $intent['ref'] ) . '"><input type="hidden" name="expected" value="' . esc_attr( $intent['version'] ) . '"><label><input type="checkbox" name="confirm" value="yes" required> I reviewed the failed endpoint and booking.</label><p><button class="button">Queue reviewed retry</button></p></form>';
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
        if(!in_array($_POST['operation']??'',array('','repair_template_quotes','retry_ha'),true))wp_die('Unknown maintenance operation.');
        if ( ( $_POST['operation'] ?? '' ) === 'retry_ha' ) $result = MBS_HomeAssistant::retry_failed( sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ), $expected );
        else $result=MBS_Space_Selection::locked(static function()use($expected){return ($_POST['operation']??'')==='repair_template_quotes'?MBS_Email_Templates::repair_template_quotes($expected):MBS_Google_Calendar::recover_legacy_lock($expected);});
        if(is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        wp_safe_redirect(admin_url('admin.php?page=mathlin-health')); exit;
    }
}
