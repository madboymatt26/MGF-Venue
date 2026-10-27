<?php
if(!defined('ABSPATH'))exit;

/** Private, user-bound, short-lived CSV downloads. No files in public uploads. */
class MBS_Export_Snapshots {
    const MAX_BYTES=5242880;
    const TTL=3600;
    private static function key($owner,$slot){return 'mbs_export_snapshot_'.(int)$owner.'_'.(int)$slot;}
    public static function save($csv,$format,$owner){
        if(!is_string($csv)||strlen($csv)>self::MAX_BYTES||!in_array($format,array('bookings','xero','sage','quickbooks'),true)||(int)$owner<1)return new WP_Error('export_size','Export exceeds its safe size or format. Choose a smaller date range.');
        for($slot=0;$slot<3;$slot++){
            $key=self::key($owner,$slot);$prior=get_option($key,null);
            if($prior!==null){if(!is_array($prior)||($prior['expires']??0)>time())continue;if(!self::expire($key,$prior))continue;}
            $job=array('id'=>wp_generate_uuid4(),'owner'=>(int)$owner,'expires'=>time()+self::TTL,'created_at'=>gmdate(DATE_RFC3339),'format'=>$format,'csv'=>$csv,'sha256'=>hash('sha256',$csv));
            if(add_option($key,$job,'',false))return array('slot'=>$slot,'id'=>$job['id'],'expires'=>$job['expires'],'sha256'=>$job['sha256']);
        }
        return new WP_Error('export_quota','Three exports are already retained for this account. Reuse an existing download or retry after its one-hour expiry.');
    }
    public static function read($owner,$slot,$id){
        if((int)$owner<1||(!is_string($slot)&&!is_int($slot))||!preg_match('/^[0-2]$/D',(string)$slot)||!is_string($id)||strlen($id)>64)return new WP_Error('export_missing','Export not available.');
        $key=self::key($owner,$slot);$job=get_option($key,null);
        if(!is_array($job)||($job['owner']??0)!==(int)$owner||!is_string($job['id']??null)||!hash_equals($job['id'],$id))return new WP_Error('export_missing','Export not available.');
        if(($job['expires']??0)<=time()){self::expire($key,$job);return new WP_Error('export_expired','Export expired. Generate a new snapshot.');}
        if(!in_array($job['format']??'',array('bookings','xero','sage','quickbooks'),true)||!is_string($job['csv']??null)||strlen($job['csv'])>self::MAX_BYTES||!hash_equals((string)($job['sha256']??''),hash('sha256',$job['csv'])))return new WP_Error('export_corrupt','Export integrity check failed. Generate a new snapshot.');
        return $job;
    }
    public static function retained($owner){
        $out=array();for($slot=0;$slot<3;$slot++){
            $key=self::key($owner,$slot);$job=get_option($key,null);
            if(is_array($job)&&($job['owner']??0)===(int)$owner&&($job['expires']??0)>time())$out[]=array('slot'=>$slot,'id'=>$job['id'],'created_at'=>$job['created_at'],'format'=>$job['format'],'expires'=>$job['expires']);
            wp_cache_delete($key,'options');
        }return$out;
    }
    /** Single byte range; all retries refer to the same frozen CSV bytes. */
    public static function byte_range($header,$length){
        if($header==='')return array(0,$length-1,false);
        if(!is_string($header)||strlen($header)>80||!preg_match('/^bytes=(\d*)-(\d*)$/D',$header,$m)||($m[1]===''&&$m[2]===''))return new WP_Error('export_range','Unsatisfiable byte range.');
        if($m[1]===''){$suffix=(int)$m[2];if($suffix<1)return new WP_Error('export_range','Unsatisfiable byte range.');$a=max(0,$length-$suffix);$z=$length-1;}
        else{$a=(int)$m[1];$z=$m[2]===''?$length-1:min($length-1,(int)$m[2]);}
        return $a<0||$a>=$length||$z<$a?new WP_Error('export_range','Unsatisfiable byte range.'):array($a,$z,true);
    }
    private static function expire($key,$expected){
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($expected)))===1;
        if($changed)wp_cache_delete($key,'options');return$changed;
    }
    public static function cleanup(){
        global $wpdb;$cursor=(int)get_option('mbs_export_cleanup_cursor',0);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_id,option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id>%d ORDER BY option_id LIMIT 100",$wpdb->esc_like('mbs_export_snapshot_').'%',$cursor));
        if(!is_array($rows)||$wpdb->last_error)return;
        foreach($rows as$row){$job=get_option($row->option_name,null);if(is_array($job)&&($job['expires']??0)<=time())self::expire($row->option_name,$job);wp_cache_delete($row->option_name,'options');$cursor=(int)$row->option_id;}
        update_option('mbs_export_cleanup_cursor',count($rows)<100?0:$cursor,false);
    }
}
