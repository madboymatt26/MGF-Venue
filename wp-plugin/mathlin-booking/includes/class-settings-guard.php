<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class MBS_Settings_Guard {
    public static function fingerprint( $email = false ) {
        $keys = $email ? array('org_name','org_address','org_phone','org_charity_number','org_logo_url','max_chase_emails','chase_interval_days','cron_time_reminders','cron_time_chase','cron_time_archive') : array('spaces','campsite_mode','campsite_roles','multi_space_enabled','min_notice_days','min_duration_hours','kitchen_enabled','kitchen_price','deposit_enabled','deposit_percentage','deposit_balance_days','access_enabled','access_code','access_hours_before','payments_enabled','auto_archive_days','terms_page_id');
        if($email) foreach(array_keys(MBS_Email_Templates::get_template_types())as$type)$keys[]='email_template_'.$type;
        $values=array();foreach($keys as$key)$values[$key]=get_option('mbs_'.$key,null);
        return hash('sha256',wp_json_encode($values));
    }
    public static function preflight( $data, $email = false ) {
        if(isset($data['expected_settings_hash'])&&!hash_equals(self::fingerprint($email),(string)$data['expected_settings_hash'])) return new WP_Error('settings_changed','Settings changed in another window. Reload before saving.');
        if(!isset($data['spaces']))return true;
        if(!is_array($data['spaces'])||!$data['spaces']||count($data['spaces'])>100)return new WP_Error('invalid_spaces','Provide between 1 and 100 spaces.');
        $proposed=array();
        foreach($data['spaces']as$row){
            if(!is_array($row)||!is_string($row['name']??null)||trim($row['name'])===''||strlen($row['name'])>255)return new WP_Error('invalid_spaces','Every space needs a valid name.');
            $name=sanitize_text_field($row['name']);if(isset($proposed[$name]))return new WP_Error('invalid_spaces','Space names must be unique.');
            foreach(array('rate_hourly','rate_daily')as$field)if(isset($row[$field])&&(!is_numeric($row[$field])||!is_finite((float)$row[$field])||(float)$row[$field]<0))return new WP_Error('invalid_spaces','Space prices must be finite non-negative numbers.');
            $proposed[$name]=array('parent'=>sanitize_text_field($row['parent']??'')?:null);
        }
        foreach($proposed as$name=>$row){$seen=array($name=>true);$parent=$row['parent'];while($parent){if(isset($seen[$parent])||!isset($proposed[$parent]))return new WP_Error('invalid_spaces','Parents must exist and must not form a dependency cycle.');$seen[$parent]=true;$parent=$proposed[$parent]['parent'];}}
        global $wpdb;
        $active=$wpdb->get_results('SELECT space FROM '.$wpdb->prefix.MBS_TABLE." WHERE status NOT IN ('cancelled','archived')");
        if(!is_array($active)||!empty($wpdb->last_error))return new WP_Error('settings_availability_unknown','Cannot verify active space allocations. Nothing was changed.');
        $current=MBS_Bookings::get_spaces();
        foreach($active as$booking)foreach(MBS_Space_Selection::members($booking->space)as$name)if(!isset($proposed[$name])||($proposed[$name]['parent']??null)!==($current[$name]['parent']??null))return new WP_Error('active_space_change','An active booking uses this space/dependency. Move or cancel it before removing, renaming or reparenting the resource.');
        return true;
    }
}
