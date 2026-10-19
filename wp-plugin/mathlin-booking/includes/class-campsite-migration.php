<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Admin-only, fixed-purpose WP Booking Calendar migration. No arbitrary SQL or executable input. */
class MBS_Campsite_Migration {
    public function init() {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_action( 'template_redirect', array( $this, 'redirect_legacy_pages' ) );
    }

    public function routes() {
        register_rest_route( 'mathlin/v1', '/admin/campsite-migration', array(
            'methods' => 'GET', 'callback' => array( $this, 'preview' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
        register_rest_route( 'mathlin/v1', '/admin/campsite-migration/(?P<action>configure|import|test|cutover|rollback)', array(
            'methods' => 'POST', 'callback' => array( $this, 'action' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
    }

    public static function schema() {
        global $wpdb;
        $schema = array();
        foreach ( array( 'booking', 'bookingdates', 'bookingtypes' ) as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
            if ( ! $columns ) return new WP_Error( 'legacy_schema', 'Legacy table not found: ' . $suffix );
            $schema[ $suffix ] = $columns;
        }
        return $schema;
    }

    public function preview( $request = null ) {
        global $wpdb;
        $schema = self::schema();
        if ( is_wp_error( $schema ) ) return $schema;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = array();
        foreach ( get_plugins() as $file => $data ) {
            if ( stripos( $data['Name'], 'Booking' ) !== false || stripos( $data['Name'], 'Ultimate Member' ) !== false ) $plugins[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active( $file ) );
        }
        $page_access = array();
        foreach ( array( 80, 1394, 1396, 1398 ) as $id ) {
            $meta = get_post_meta( $id ); $safe = array();
            foreach ( $meta as $key => $values ) if ( preg_match( '/^(um_|_um_)|restrict/i', $key ) ) $safe[$key] = array_map( 'maybe_unserialize', $values );
            $page = get_post( $id );
            $page_access[$id] = array( 'status' => $page ? $page->post_status : null, 'parent' => $page ? $page->post_parent : null, 'access_meta' => $safe );
        }
        $role_caps = array();
        foreach ( wp_roles()->roles as $key => $role ) {
            $role_caps[$key] = array( 'name' => $role['name'], 'caps' => array_keys( array_filter( $role['capabilities'] ) ) );
        }
        $samples = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}booking ORDER BY booking_id DESC LIMIT 3", ARRAY_A );
        $sample_shapes = array();
        foreach ( $samples as $sample ) {
            $form = self::parse_form( $sample['form'] ?? '' );
            $sample_shapes[] = array( 'booking_id' => $sample['booking_id'] ?? null, 'booking_type' => $sample['booking_type'] ?? null, 'is_trash' => $sample['is_trash'] ?? null, 'form_keys' => array_keys( $form ) );
        }
        $types = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bookingtypes", ARRAY_A );
        $date_samples = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bookingdates ORDER BY booking_id DESC, booking_date ASC LIMIT 12", ARRAY_A );
        $plan = self::plan();
        if ( ! is_wp_error( $plan ) ) unset( $plan['rows'] );
        if ( $request && $request->get_param( 'summary' ) ) return rest_ensure_response( array( 'plan' => is_wp_error( $plan ) ? array( 'error' => $plan->get_error_message() ) : $plan, 'migration' => get_option( 'mbs_wpbc_migration', array() ) ) );
        return rest_ensure_response( array( 'schema' => $schema, 'plugins' => $plugins, 'page_access' => $page_access, 'role_caps' => $role_caps, 'sample_shapes' => $sample_shapes, 'resources' => $types, 'date_samples' => $date_samples, 'timezone' => get_option( 'timezone_string' ), 'gmt_offset' => get_option( 'gmt_offset' ), 'mode' => MBS_Campsite_Policy::enabled(), 'plan' => is_wp_error( $plan ) ? array( 'error' => $plan->get_error_message() ) : $plan ) );
    }

    public static function parse_form( $form ) {
        $values = array();
        foreach ( explode( '~', (string) $form ) as $part ) {
            $field = explode( '^', $part, 3 );
            if ( count( $field ) !== 3 ) continue;
            $key = preg_replace( '/\d+(?:\[\])?$/', '', $field[1] );
            $values[$key] = sanitize_textarea_field( rawurldecode( $field[2] ) );
        }
        return $values;
    }

    public static function normalise_dates( $dates ) {
        $days = array();
        foreach ( $dates as $date ) {
            $stamp = (string) $date['booking_date'];
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $stamp ) || ! MBS_Campsite_Policy::valid_date( substr( $stamp, 0, 10 ) ) ) return new WP_Error( 'legacy_date', 'Unrecognised legacy timestamp.' );
            $days[substr($stamp,0,10)][] = $date;
        }
        ksort( $days ); $normal = array();
        foreach ( $days as $day => $entries ) {
            usort( $entries, static function($a,$b) { return strcmp($a['booking_date'],$b['booking_date']); } );
            $approvals = array_unique( array_map( static function($e) { return (string)$e['approved']; }, $entries ) );
            if ( count($approvals)!==1 || ! in_array((string)reset($approvals),array('0','1'),true) ) return new WP_Error('legacy_approval','Mixed or unknown approval flags on '.$day);
            $times = array_values(array_unique(array_map(static function($e){return substr($e['booking_date'],11);},$entries)));
            if ( count($times)===1 && $times[0]==='00:00:00' ) { $all_day=true;$start='00:00:00';$end='23:59:59'; }
            elseif ( count($times)===2 && $times[1]>$times[0] ) { $start=$times[0];$end=$times[1];$all_day=substr($start,0,5)==='00:01' && substr($end,0,5)==='23:59'; }
            else return new WP_Error('legacy_times','Ambiguous time endpoints on '.$day);
            $normal[] = array('day'=>$day,'start'=>$start,'end'=>$end,'all_day'=>$all_day,'status'=>(string)reset($approvals)==='1'?'confirmed':'pending');
        }
        return $normal;
    }

    public static function plan() {
        global $wpdb;
        $schema=self::schema(); if(is_wp_error($schema))return $schema;
        foreach(array('booking'=>array('booking_id','booking_type','form','trash','is_trash'),'bookingdates'=>array('booking_id','booking_date','approved'),'bookingtypes'=>array('booking_type_id','title')) as $table=>$required) if(array_diff($required,$schema[$table]))return new WP_Error('legacy_schema','Required source columns missing from '.$table);
        $today=wp_date('Y-m-d');
        $sources=$wpdb->get_results($wpdb->prepare("SELECT b.* FROM {$wpdb->prefix}booking b WHERE EXISTS (SELECT 1 FROM {$wpdb->prefix}bookingdates d WHERE d.booking_id=b.booking_id AND d.booking_date >= %s) ORDER BY b.booking_id",$today.' 00:00:00'),ARRAY_A);
        $types=$wpdb->get_results("SELECT booking_type_id,title FROM {$wpdb->prefix}bookingtypes",OBJECT_K);
        $rows=array();$errors=array();$excluded=array();$source_count=0;$summary=array('pending'=>0,'confirmed'=>0);$missing_contacts=array();
        $orphans=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bookingdates d LEFT JOIN {$wpdb->prefix}booking b ON b.booking_id=d.booking_id WHERE b.booking_id IS NULL AND d.booking_date >= %s",$today.' 00:00:00'));
        if($orphans)$errors[]='Future legacy date rows without a booking: '.$orphans;
        $synced=0;
        foreach($sources as $source){
            $id=(int)$source['booking_id'];
            if(in_array(strtolower((string)$source['is_trash']),array('1','true'),true)||in_array(strtolower((string)$source['trash']),array('1','true'),true)){$excluded[]=$id;continue;}
            $source_count++;
            if(!empty($source['sync_gid']))$synced++;
            $title=isset($types[$source['booking_type']])?html_entity_decode($types[$source['booking_type']]->title,ENT_QUOTES,'UTF-8'):'';
            if(!isset(MBS_Bookings::get_spaces()[$title])){$errors[]='Source '.$id.': unmapped resource '.$title;continue;}
            $dates=$wpdb->get_results($wpdb->prepare("SELECT booking_date,approved FROM {$wpdb->prefix}bookingdates WHERE booking_id=%d AND booking_date >= %s ORDER BY booking_date",$id,$today.' 00:00:00'),ARRAY_A);
            $normal=self::normalise_dates($dates);
            if(is_wp_error($normal)){$errors[]='Source '.$id.': '.$normal->get_error_message();continue;}
            $fields=self::parse_form($source['form']);
            $email=sanitize_email($fields['email']??'');
            if(!$email||!is_email($email))$missing_contacts[]=$id;
            foreach($normal as $slot){
                $ref='MBS-L'.$id.'-'.substr(str_replace('-','',$slot['day']),2);
                if(strlen($ref)>20){$errors[]='Source reference too long: '.$id;continue;}
                $source_hash=hash('sha256',wp_json_encode(array($id,$source['booking_type'],$source['form'],$slot)));
                $extra=array('_mbs_campsite_span'=>'daily','_mbs_legacy'=>array('plugin'=>'wpbc','booking_id'=>$id,'source_hash'=>$source_hash,'start'=>$slot['start'],'end'=>$slot['end'],'approval'=>$slot['status']));
                foreach(MBS_Custom_Fields::get_fields() as $field){$label=strtolower($field['label']);if(strpos($label,'permit')!==false)$extra[$field['id']]=$fields['permit']??'';elseif($label==='group')$extra[$field['id']]=$fields['group']??'';elseif($label==='section')$extra[$field['id']]=$fields['section']??'';}
                $record=array('ref'=>$ref,'status'=>$slot['status'],'name'=>$fields['name']??('Legacy booking '.$id),'organisation'=>$fields['group']??'','email'=>$email,'phone'=>$fields['phone']??'','address'=>'','space'=>$title,'kitchen'=>0,'booking_date'=>$slot['day'],'booking_date_end'=>$slot['day'],'all_day'=>$slot['all_day']?1:0,'scout_use'=>0,'pricing_tier'=>'standard','start_time'=>$slot['start'],'end_time'=>$slot['end'],'attendees'=>0,'purpose'=>trim('Campsite booking '.($fields['group']??'').' '.($fields['section']??'')),'notes'=>$fields['details']??'','amount'=>0,'invoice_number'=>'','legacy_billing_excluded'=>1,'reminder_sent'=>1,'access_sent'=>1,'feedback_sent'=>1,'is_public'=>0,'custom_fields'=>wp_json_encode($extra));
                $existing=MBS_Bookings::get($ref);
                if($existing){$stored=json_decode($existing->custom_fields,true);if(($stored['_mbs_legacy']['source_hash']??'')!==$source_hash)$errors[]='Source '.$id.' changed after import; no automatic overwrite will occur.';}
                if(count($normal)>1)$record['series_id']='SER-L'.$id;
                $record['admin_notes']='Migrated from Booking Calendar #'.$id.'. Original approval and local time endpoints preserved. '.sanitize_textarea_field($source['remark']??'');
                if($existing){
                    // An explicitly consolidated source remains an archived recovery record.
                    $pointer=$stored['_mbs_whole_park_replacement']??'';
                    if($existing->status==='archived'&&$pointer){
                        $replacement=MBS_Bookings::get($pointer);$metadata=$replacement?json_decode($replacement->custom_fields,true):array();
                        if($replacement&&$replacement->space==='Whole Park'&&in_array($ref,$metadata['_mbs_whole_park_sources']??array(),true)&&$replacement->booking_date===$record['booking_date']&&$replacement->booking_date_end===$record['booking_date_end']&&$replacement->start_time===$record['start_time']&&$replacement->end_time===$record['end_time']&&$replacement->status===$record['status']){$existing=clone $existing;$existing->status=$record['status'];}
                    }
                    foreach(array('status','space','booking_date','booking_date_end','start_time','end_time','email','name','phone','organisation','notes','all_day') as $key)if((string)$existing->$key!==(string)$record[$key]){$errors[]='Imported target '.$ref.' differs from its source; review before cutover.';break;}
                    if((float)$existing->amount!==0.0||!empty($existing->invoice_number)||($existing->series_id??'')!==($record['series_id']??''))$errors[]='Imported target '.$ref.' has different billing or group metadata.';
                }
                $rows[$ref]=$record;$summary[$slot['status']]++;
            }
        }
        $conflicts=array();$list=array_values($rows);
        for($i=0;$i<count($list);$i++)for($j=$i+1;$j<count($list);$j++){
            $a=$list[$i];$b=$list[$j];if($a['space']!==$b['space']||$a['booking_date']!==$b['booking_date'])continue;
            if($a['all_day']||$b['all_day']||($a['start_time']<$b['end_time']&&$a['end_time']>$b['start_time']))$conflicts[]=array('a'=>$a['ref'],'b'=>$b['ref'],'space'=>$a['space'],'date'=>$a['booking_date']);
        }
        $matched=0;foreach($rows as $ref=>$row)if(MBS_Bookings::get($ref))$matched++;
        $groups=array();foreach($rows as $row)if(!empty($row['series_id']))$groups[$row['series_id']]=true;
        return array('source_bookings'=>$source_count,'target_slots'=>count($rows),'grouped_requests'=>count($groups),'status_counts'=>$summary,'future_orphan_date_rows'=>$orphans,'source_sync_gid_count'=>$synced,'excluded_trash_ids'=>$excluded,'missing_contact_ids'=>array_values(array_unique($missing_contacts)),'errors'=>$errors,'conflicts'=>$conflicts,'already_imported'=>$matched,'fingerprint'=>hash('sha256',wp_json_encode($rows)),'rows'=>$rows);
    }

    public function action($request){
        if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'stowmarketscouts.org.uk' ) return new WP_Error( 'migration_site', 'This reviewed migration is for Stowmarket only.', array( 'status' => 403 ) );
        $action=$request->get_param('action');
        if($action==='configure')return $this->configure($request);
        if($action==='test')return $this->acceptance();
        if($action==='cutover')return $this->cutover($request);
        if($action==='rollback')return $this->rollback();
        if($action==='import' && get_option('mbs_campsite_multiday_batch'))return new WP_Error('migration_repaired','Imported full-day records have been combined into stays. Reimport is blocked to prevent duplicate daily bookings; use the stored recovery snapshot for any reviewed reversal.',array('status'=>409));
        $plan=self::plan();if(is_wp_error($plan))return $plan;
        if($plan['errors']||$plan['conflicts'])return new WP_Error('migration_review_required','Source errors or overlaps require review before import.',array('status'=>409,'errors'=>$plan['errors'],'conflicts'=>$plan['conflicts']));
        if(!hash_equals($plan['fingerprint'],(string)$request->get_param('fingerprint')))return new WP_Error('migration_changed','The source changed. Refresh and review the preview.',array('status'=>409));
        global $wpdb;
        if((int)$wpdb->get_var("SELECT GET_LOCK('mbs_wpbc_migration',10)")!==1)return new WP_Error('migration_busy','Another migration is running.');
        try{
            if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Could not start migration transaction.');
            $created=0;
            foreach($plan['rows'] as $ref=>$row){if(MBS_Bookings::get($ref))continue;$row['modification_token']=wp_generate_password(32,false);if($row['email']){$user=get_user_by('email',$row['email']);if($user)$row['user_id']=$user->ID;}if($wpdb->insert($wpdb->prefix.MBS_TABLE,$row)===false)throw new RuntimeException('Could not import '.$ref);$created++;}
            $groups=array();foreach($plan['rows'] as $ref=>$row)if(!empty($row['series_id']))$groups[$row['series_id']][]=$ref;
            foreach($groups as $series_ref=>$refs){
                if(MBS_Series::get($series_ref))continue;
                $first=$plan['rows'][$refs[0]];$dates=array();$occurrences=array();$all_confirmed=true;
                foreach($refs as $ref){$row=$plan['rows'][$ref];$dates[]=$row['booking_date'];$occurrences[]=array('date'=>$row['booking_date'],'status'=>'accepted','ref'=>$ref);if($row['status']!=='confirmed')$all_confirmed=false;}
                $data=$first;$data['booking_date_end']=$first['booking_date'];$data['recurrence_pattern']='selected';$data['recurrence_dates']=$dates;
                $series=MBS_Series::create_from_request($series_ref,$data,max($dates),$occurrences,$refs);
                if(is_wp_error($series))throw new RuntimeException($series->get_error_message());
                if($wpdb->update($wpdb->prefix.MBS_SERIES_TABLE,array('status'=>$all_confirmed?'confirmed':'pending','billing_mode'=>'none','billing_treatment'=>'none','payment_method'=>'none','automatic_reminders'=>0),array('series_ref'=>$series_ref))===false)throw new RuntimeException('Could not retain legacy group approval.');
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('Could not commit migration.');
            update_option('mbs_wpbc_migration',array('fingerprint'=>$plan['fingerprint'],'slots'=>$plan['target_slots'],'source_bookings'=>$plan['source_bookings'],'completed_at'=>current_time('mysql'),'status_counts'=>$plan['status_counts']),false);
            MBS_Audit_Log::log('WPBC-MIGRATION','legacy_import','Imported '.$created.' slots; existing matches '.$plan['already_imported'].'. Customer notifications suppressed.');
            $verified=self::plan();unset($verified['rows']);return rest_ensure_response(array('created'=>$created,'notifications_sent'=>0,'verification'=>$verified));
        }catch(Exception $e){$wpdb->query('ROLLBACK');return new WP_Error('migration_failed',$e->getMessage(),array('status'=>500));}finally{$wpdb->get_var("SELECT RELEASE_LOCK('mbs_wpbc_migration')");}
    }

    public function redirect_legacy_pages() {
        if ( ! get_option( 'mbs_campsite_cutover', false ) || is_admin() || is_preview() ) return;
        $path = untrailingslashit( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
        $target = 0;
        if ( is_page( array( 80, 1370 ) ) ) $target = 1394;
        if ( in_array( $path, array( '/volunteers/haughley-park/bookings', '/volunteers/haughley-park/edit' ), true ) ) $target = 1396;
        if ( $target ) { nocache_headers(); wp_safe_redirect( get_permalink( $target ), 302 ); exit; }
    }

    /** Isolated fixture workflow; all generated mail is captured, never transmitted. */
    private function acceptance() {
        global $wpdb;
        if ( ! MBS_Campsite_Policy::enabled() || get_option( 'mbs_campsite_booking_open' ) ) return new WP_Error( 'test_staging', 'Run acceptance only while the replacement form is closed.' );
        $refs = array(); $series_ref = ''; $messages = array(); $checks = array();
        $capture = function( $pre, $args ) use ( &$messages ) { $messages[] = $args; return true; };
        add_filter( 'pre_wp_mail', $capture, PHP_INT_MAX, 2 );
        $original_user = get_current_user_id();
        $invoices_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE );
        $documents_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE );
        $assert = function( $condition, $label ) use ( &$checks ) { if ( ! $condition ) throw new RuntimeException( $label ); $checks[] = $label; };
        try {
            $user = wp_get_current_user();
            $assert( MBS_Campsite_Policy::can_book(), 'Administrator access allowed' );
            wp_set_current_user( 0 );
            $assert( ! MBS_Campsite_Policy::can_book(), 'Guest booking/calendar access denied' );
            wp_set_current_user( $original_user );
            $seed = ( new DateTimeImmutable( wp_date( 'Y-m-d' ), wp_timezone() ) )->modify( '+35 days' )->modify( 'next saturday' );
            $found = false;
            for ( $attempt = 0; $attempt < 40; $attempt++, $seed = $seed->modify( '+1 week' ) ) {
                $clear = true;
                for ( $n = -1; $n <= 16; $n++ ) { $date = $seed->modify( ($n >= 0 ? '+' : '') . $n . ' days' )->format( 'Y-m-d' ); if ( MBS_Bookings::check_conflicts( 'Whole Park', $date, null, null, true ) || MBS_Blocked_Dates::is_blocked( $date, 'Whole Park' ) ) { $clear = false; break; } }
                if ( $clear ) { $found = true; break; }
            }
            $assert( $found, 'Found an empty isolated test interval' );
            $purpose = 'MGF migration acceptance ' . wp_generate_password( 10, false );
            $base = array( 'name' => 'Migration acceptance test', 'email' => $user->user_email, 'space' => 'Cabin', 'booking_date' => $seed->format( 'Y-m-d' ), 'booking_date_end' => $seed->format( 'Y-m-d' ), 'start_time' => '09:00', 'end_time' => '12:00', 'booking_span' => 'daily', 'attendees' => 1, 'purpose' => $purpose, 'accept_terms' => 1 );
            $one = MBS_Bookings::create( $base, true );
            $assert( ! is_wp_error( $one ), 'One-off request saved' ); $refs[] = $one['ref'];
            $assert( $one['status'] === 'pending' && (float) $one['amount'] === 0.0 && empty( $one['invoice_number'] ), 'Free request remains pending without invoice' );
            $assert( count( MBS_Bookings::check_conflicts( 'Whole Park', $base['booking_date'], '09:00', '12:00' ) ) > 0, 'Pending child blocks Whole Park' );
            $assert( ! MBS_Bookings::check_conflicts( 'Paddock', $base['booking_date'], '09:00', '12:00' ), 'Child does not block siblings' );
            MBS_Email::notify_booker( $one );
            $assert( MBS_Bookings::update_status( $one['ref'], 'confirmed', false ), 'One-off approval succeeds' );
            $assert( MBS_Bookings::get( $one['ref'] )->status === 'confirmed', 'No-charge approval is confirmed, not paid' );
            MBS_Email::notify_confirmed( MBS_Bookings::get( $one['ref'] ) );
            $assert( MBS_Bookings::update_status( $one['ref'], 'cancelled', false ), 'One-off cancellation succeeds' );
            $assert( ! MBS_Bookings::check_conflicts( 'Whole Park', $base['booking_date'], '09:00', '12:00' ), 'Cancellation releases parent availability' );
            $camp = $base; $camp['booking_date'] = $seed->modify( '-1 day' )->format( 'Y-m-d' ); $camp['booking_date_end'] = $seed->modify( '+2 days' )->format( 'Y-m-d' ); $camp['start_time'] = '17:00'; $camp['end_time'] = '10:00'; $camp['booking_span'] = 'continuous';
            $stay = MBS_Bookings::create( $camp, true );
            $assert( ! is_wp_error( $stay ), 'Continuous overnight camp saved' ); $refs[] = $stay['ref'];
            $assert( count( MBS_Bookings::check_conflicts( 'Whole Park', $seed->format( 'Y-m-d' ), '09:00', '12:00' ) ) > 0, 'Continuous camp occupies intermediate daytime' );
            $assert( ! MBS_Bookings::check_conflicts( 'Whole Park', $camp['booking_date_end'], '10:00', '12:00' ), 'Departure releases the area at the correct time' );
            $series_data = $base; $series_data['booking_date'] = $seed->modify( '+7 days' )->format( 'Y-m-d' ); $series_data['booking_date_end'] = $series_data['booking_date'];
            $series_result = MBS_Bookings::create_recurring( $series_data, $seed->modify( '+14 days' )->format( 'Y-m-d' ), true );
            $assert( ! is_wp_error( $series_result ), 'Grouped request saved' );
            $series_ref = $series_result['series_id']; $refs = array_merge( $refs, $series_result['refs'] );
            $series = MBS_Series::get( $series_ref );
            $portal = ( new MBS_Hirer_Portal() )->shortcode_portal( array() );
            $assert( strpos( $portal, $series_ref ) !== false && strpos( $portal, $one['ref'] ) !== false && strpos( $portal, 'No hire charge' ) !== false, 'Owner portal displays grouped and one-off bookings without hire payment wording' );
            $approved = MBS_Series::approve( $series_ref, 'pending', $series->version, false );
            $assert( ! is_wp_error( $approved ) && MBS_Series::get( $series_ref )->status === 'confirmed', 'Grouped approval uses no-charge billing' );
            $series = MBS_Series::get( $series_ref );
            $edited = MBS_Campsite_Policy::edit_series( $series_ref, array( 'purpose' => $purpose . ' updated' ), $series->version, false );
            $assert( ! is_wp_error( $edited ) && $edited['updated'] === 2, 'Atomic grouped amendment succeeds' );
            $assert( is_wp_error( MBS_Campsite_Policy::edit_series( $series_ref, array( 'purpose' => $purpose ), $series->version, false ) ), 'Stale grouped amendment rejected' );
            $series = MBS_Series::get( $series_ref ); $occurrences = MBS_Series::occurrences( $series_ref, false );
            $request_id = MBS_Series_Request::submit( array( 'series_ref' => $series_ref, 'kind' => 'cancel', 'scope' => 'one', 'refs' => array( $occurrences[0]->ref ), 'notes' => 'Isolated migration acceptance test' ) );
            $assert( ! is_wp_error( $request_id ) && (int) $request_id > 0, 'Owner can request cancellation without immediately changing bookings' );
            $cancel = MBS_Series::cancel( $series_ref, 'selected', $series->status, $series->version, false, '', array( $occurrences[0]->ref ), (int) $request_id );
            $assert( ! is_wp_error( $cancel ) && MBS_Bookings::get( $occurrences[0]->ref )->status === 'cancelled' && MBS_Bookings::get( $occurrences[1]->ref )->status === 'confirmed', 'Approved selected cancellation leaves other date intact' );
            $assert( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE ) === $invoices_before && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE ) === $documents_before, 'No hire invoices or invoice documents generated' );
            $assert( count( $messages ) >= 2, 'Normal email rendering reached WordPress mail transport (captured only)' );
            foreach ( array_slice( $messages, 0, 2 ) as $mail ) $assert( strpos( $mail['message'], 'No payment required' ) !== false, 'Booker email clearly confirms no payment required' );
            $result = array( 'passed' => true, 'version' => MBS_VERSION, 'build' => hash_file( 'sha256', __FILE__ ), 'checks' => $checks, 'emails_transmitted' => 0, 'mail_transport' => 'captured; delivery not tested', 'checked_at' => current_time( 'mysql' ) );
        } catch ( Throwable $error ) { $result = array( 'passed' => false, 'version' => MBS_VERSION, 'checks' => $checks, 'error' => $error->getMessage(), 'emails_transmitted' => 0 ); }
        finally {
            wp_set_current_user( $original_user ); remove_filter( 'pre_wp_mail', $capture, PHP_INT_MAX );
            // Exact references generated in this invocation only; never touch imported/live bookings.
            foreach ( $refs as $ref ) { $wpdb->delete( $wpdb->prefix . 'mathlin_mod_requests', array( 'booking_ref' => $ref ) ); $wpdb->delete( $wpdb->prefix . MBS_TABLE, array( 'ref' => $ref ) ); }
            if ( $series_ref ) $wpdb->delete( $wpdb->prefix . MBS_SERIES_TABLE, array( 'series_ref' => $series_ref ) );
        }
        update_option( 'mbs_campsite_acceptance', $result, false );
        return rest_ensure_response( $result );
    }

    private function free_templates() {
        $bodies = array(
            'booking_received' => "Hi {name},\n\nWe've received your request for {space} on {date}. Your reference is {ref}.\n\nThe booking coordinator will review your request. There is no hire charge and no payment is required. Please wait for approval before treating the booking as confirmed.\n\nFor any questions, contact {admin_email}.",
            'booking_confirmed' => "Hi {name},\n\nYour campsite booking has been approved. The confirmed details are shown below.\n\nThere is no hire charge and no payment is required. Please follow the campsite terms and health guidance.\n\nFor any questions, contact {admin_email}.",
            'recurring_summary' => "Hi {name},\n\nWe've received your grouped campsite booking request. The dates below await review by the booking coordinator. There is no hire charge or payment required.",
            'series_confirmed' => "Hi {name},\n\nYour grouped campsite booking has been approved. The confirmed dates are shown below. There is no hire charge or payment required.",
            'series_cancelled' => "Hi {name},\n\nThe campsite booking dates listed below have been cancelled. Any other dates in your group are unchanged. There is no hire payment to refund.",
            'modification_approved' => "Hi {name},\n\nYour requested changes have been approved.\n\nSpace: {space}\nDate: {date}\nTime: {time}\n\nThere is no hire charge or payment required. Contact {admin_email} if you have any questions."
        );
        if ( ! get_option( 'mbs_campsite_templates_backup' ) ) { $backup = array(); foreach ( $bodies as $type => $body ) $backup[$type] = get_option( 'mbs_email_template_' . $type, null ); update_option( 'mbs_campsite_templates_backup', $backup, false ); }
        foreach ( $bodies as $type => $body ) { $template = MBS_Email_Templates::get_template( $type ); if ( $template ) MBS_Email_Templates::save_template( $type, $template['subject'], $body ); }
    }

    private function cutover( $request ) {
        $plan = self::plan(); if ( is_wp_error( $plan ) ) return $plan;
        $test = get_option( 'mbs_campsite_acceptance', array() );
        if ( ( $test['build'] ?? '' ) !== hash_file( 'sha256', __FILE__ ) ) return new WP_Error( 'cutover_build_changed', 'Rerun acceptance on the installed migration build.' );
        if ( empty( $test['passed'] ) || ( $test['version'] ?? '' ) !== MBS_VERSION || $plan['errors'] || $plan['conflicts'] || $plan['missing_contact_ids'] || $plan['already_imported'] !== $plan['target_slots'] || ! hash_equals( $plan['fingerprint'], (string) $request->get_param( 'fingerprint' ) ) ) return new WP_Error( 'cutover_not_ready', 'Reconciliation and acceptance must pass against the current source.', array( 'status' => 409 ) );
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( get_option( 'mbs_campsite_cutover' ) ) return rest_ensure_response( array( 'already_live' => true ) );
        $backup = array( 'plugins' => array(), 'pages' => array(), 'fingerprint' => $plan['fingerprint'] );
        foreach ( array( 'booking/wpdev-booking.php', 'booking.personal.10.8/wpdev-booking.php' ) as $file ) if ( is_plugin_active( $file ) ) $backup['plugins'][] = $file;
        foreach ( array( 1394, 1396, 1398 ) as $id ) { $page = get_post( $id ); if ( ! $page || $page->post_type !== 'page' ) return new WP_Error( 'cutover_page', 'A reviewed replacement page is missing.' ); $backup['pages'][$id] = array( 'ID' => $id, 'post_status' => $page->post_status, 'post_content' => $page->post_content, 'post_parent' => $page->post_parent ); }
        update_option( 'mbs_campsite_cutover_backup', $backup, false );
        deactivate_plugins( $backup['plugins'], true );
        $locked_plan = self::plan();
        if ( is_wp_error( $locked_plan ) || $locked_plan['fingerprint'] !== $plan['fingerprint'] ) { foreach ( $backup['plugins'] as $file ) activate_plugin( $file, '', false, true ); return new WP_Error( 'cutover_source_changed', 'Legacy activity changed during cutover. The old calendar has been restored; reconcile before retrying.' ); }
        $nav = '<p><a href="' . esc_url( get_permalink(1394) ) . '">Book and check availability</a> · <a href="' . esc_url( get_permalink(1398) ) . '">Availability calendar</a> · <a href="' . esc_url( get_permalink(1396) ) . '">Manage your bookings</a> · <a href="' . esc_url( get_permalink(455) ) . '">Campsite terms</a> · <a href="https://stowmarketscouts.org.uk/wp-content/uploads/2020/06/Health-Warning-for-Camping.pdf">Avoiding ill health at camp</a></p>';
        $content = array(1394 => $nav . '<h2>Haughley Park bookings</h2><p>For approved campsite users. Weekday arrival is from 17:00 unless agreed with the booking coordinator. Please give at least 14 days notice. Choose a day session or a continuous overnight stay. All bookings require approval; there is no hire charge.</p>[mathlin_booking]',1398 => $nav . '<h2>Haughley Park availability</h2>[mathlin_calendar]',1396 => $nav . '<h2>Manage your Haughley Park bookings</h2><p>Sign in with the email address used for your bookings. Future reservations from the previous calendar are included. Dates from the same original request are grouped together. You can request changes or cancellations for review by the booking coordinator.</p>[mathlin_portal]');
        foreach ( $content as $id => $body ) { $saved = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_parent' => 338, 'post_content' => $body ), true ); if ( is_wp_error( $saved ) ) { $this->rollback(); return $saved; } }
        update_option( 'mbs_campsite_cutover', array( 'at' => current_time('mysql'), 'fingerprint' => $plan['fingerprint'] ), false );
        update_option( 'mbs_campsite_booking_open', 1 );
        MBS_Audit_Log::log( 'WPBC-MIGRATION', 'cutover', 'Replacement pages published; original pages, legacy plugin files and data retained. Source approvals unchanged.' );
        return rest_ensure_response( array( 'live' => true, 'slots' => $plan['target_slots'], 'source_bookings' => $plan['source_bookings'], 'status_counts' => $plan['status_counts'], 'legacy_plugins_deactivated' => $backup['plugins'], 'pages' => array_map( 'get_permalink', array(1394,1396,1398) ), 'rollback' => 'POST /mathlin/v1/admin/campsite-migration/rollback' ) );
    }

    private function rollback() {
        $backup = get_option( 'mbs_campsite_cutover_backup', array() );
        if ( ! $backup ) return new WP_Error( 'rollback_missing', 'No cutover backup exists.' );
        update_option( 'mbs_campsite_booking_open', 0 ); delete_option( 'mbs_campsite_cutover' );
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach ( $backup['pages'] as $page ) { $saved = wp_update_post( $page, true ); if ( is_wp_error( $saved ) ) return $saved; }
        foreach ( $backup['plugins'] as $file ) { $active = activate_plugin( $file, '', false, true ); if ( is_wp_error( $active ) ) return $active; }
        return rest_ensure_response( array( 'legacy_restored' => true, 'replacement_form_closed' => true, 'mgf_bookings_retained' => true, 'warning' => 'Any bookings received after cutover must be reconciled before operating the old calendar.' ) );
    }

    private function configure($request){
        $spaces=$request->get_param('spaces');
        if(!is_array($spaces)||array_keys($spaces)!==array_keys(MBS_Bookings::get_spaces()))return new WP_Error('configuration_spaces','Supply the existing resource names in their current order.');
        foreach($spaces as $name=>$space){if((float)($space['rate_hourly']??-1)!==0.0||(float)($space['rate_daily']??-1)!==0.0||(int)($space['capacity']??0)!==($name==='Whole Park'?1000:100))return new WP_Error('configuration_spaces','Invalid reviewed campsite configuration.');if(($name==='Whole Park'&&($space['parent']??null)!==null)||($name!=='Whole Park'&&($space['parent']??null)!=='Whole Park'))return new WP_Error('configuration_dependencies','Preserve Whole Park parent/child dependencies.');}
        $access=get_post_meta(80,'um_content_restriction',true);
        if(empty($access['_um_custom_access_settings'])||(string)($access['_um_accessible']??'')!=='2'||empty($access['_um_access_roles']))return new WP_Error('configuration_access','The legacy page access restriction needs manual review.');
        $keys=array('mbs_campsite_mode','mbs_campsite_roles','mbs_campsite_booking_open','mbs_spaces','mbs_bank_sort_code','mbs_bank_account_number','mbs_kitchen_enabled','mbs_min_notice_days','timezone_string','gmt_offset');
        if(!get_option('mbs_campsite_settings_backup')){$backup=array();foreach($keys as $key)$backup[$key]=get_option($key,null);update_option('mbs_campsite_settings_backup',$backup,false);}
        $roles=array_keys(array_filter($access['_um_access_roles']));
        update_option('mbs_campsite_roles',$roles);update_option('mbs_campsite_booking_open',0);update_option('mbs_campsite_mode',1);update_option('mbs_spaces',$spaces);update_option('mbs_kitchen_enabled',0);update_option('mbs_min_notice_days',14);
        if(get_option('mbs_bank_sort_code')==='12-34-56')update_option('mbs_bank_sort_code','');if(get_option('mbs_bank_account_number')==='12345678')update_option('mbs_bank_account_number','');
        update_option('timezone_string','Europe/London');
        // Preserve page-level audience exactly; the portal itself still requires approved account eligibility.
        foreach(array(1394,1398) as $id)update_post_meta($id,'um_content_restriction',$access);
        $manager=get_role('um_bookings-manager');if($manager)$manager->add_cap('mbs_manage_bookings');
        $this->free_templates();
        return rest_ensure_response(array('configured'=>true,'booking_open'=>false,'roles'=>$roles,'timezone'=>wp_timezone_string(),'spaces'=>MBS_Bookings::get_spaces(),'legacy_manager_capability'=>$manager?$manager->has_cap('mbs_manage_bookings'):false));
    }
}
