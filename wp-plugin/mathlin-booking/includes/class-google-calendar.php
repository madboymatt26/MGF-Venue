<?php
/** Protected Google projection. Estate references are immutable; owned lifecycle is opt-in. */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__.'/class-booking-intervals.php';
require_once __DIR__ . '/class-calendar-reference-links.php';

class MBS_Google_Calendar {
    private static $lease = null;
    private static function lock_key() { global $wpdb; return 'mbs_gcal_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 32 ); }
    const OPTION = 'mbs_google_calendar';
    const HOOK = 'mbs_google_calendar_tick';
    const SCOPES = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    public function init() {
        add_filter( 'cron_schedules', array( $this, 'schedules' ) );
        add_action( self::HOOK, array( $this, 'tick' ) );
        add_action( 'admin_menu', function() { add_submenu_page( 'mathlin-booking', 'Calendar Sync', 'Calendar Sync', 'manage_options', 'mathlin-google-calendar', array( $this, 'render' ) ); } );
        add_action( 'admin_post_mbs_gcal', array( $this, 'handle' ) );
        add_action( 'admin_post_mbs_gcal_oauth_callback', array( $this, 'callback' ) );
        add_action( 'wp_ajax_mbs_gcal_booking', array( $this, 'booking_ajax' ) );
        add_action( 'rest_api_init', function() {
            register_rest_route( 'mathlin/v1', '/admin/google-calendar', array( 'methods' => 'GET', 'permission_callback' => function() { return current_user_can( 'manage_options' ); }, 'callback' => function( $request ) {
                if ( ! $request->get_param( 'preview' ) ) return rest_ensure_response( self::status() );
                try {
                    $snapshot = self::snapshot();
                    // Deliberately redact Google descriptions/attendees/private metadata from the API.
                    return rest_ensure_response( array( 'status' => self::status(), 'items' => array_map( function( $op ) { $e = $op['want'] ?: $op['event']; return array( 'booking_ref' => $op['ref'], 'action' => $op['action'], 'reason' => $op['reason'], 'summary' => $e['summary'] ?? '', 'start' => $e['start'] ?? null, 'end' => $e['end'] ?? null, 'google_event' => self::display_event( $op['event'] ), 'candidates' => array_map( array( __CLASS__, 'display_event' ), $op['candidates'] ), 'overlapping_events' => count( $op['candidates'] ) ); }, $snapshot['ops'] ), 'estate_only' => array_map( array( __CLASS__, 'display_event' ), self::estate_only( $snapshot ) ), 'history' => self::history() ) );
                } catch ( Throwable $e ) { return new WP_Error( 'google_calendar_unavailable', $e->getMessage(), array( 'status' => 503 ) ); }
            } ) );
        } );
        if ( ! wp_next_scheduled( self::HOOK ) ) wp_schedule_event( time() + 300, 'mbs_gcal_five_minutes', self::HOOK );
    }
    public function schedules( $s ) { $s['mbs_gcal_five_minutes'] = array( 'interval' => 300, 'display' => 'MGF Calendar: every five minutes' ); return $s; }
    public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
    private static function settings() { return (array) get_option( self::OPTION, array() ); }
    private static function save( $s ) { update_option( self::OPTION, $s, false ); }
    public static function colour_palette() {
        return array( '' => 'Calendar default', '1' => 'Lavender', '2' => 'Sage', '3' => 'Grape', '4' => 'Flamingo', '5' => 'Banana', '6' => 'Tangerine', '7' => 'Peacock', '8' => 'Graphite', '9' => 'Blue (Blueberry)', '10' => 'Green (Basil)', '11' => 'Tomato' );
    }
    public static function colour_settings( $settings, $strict = false ) {
        $raw = is_array( $settings['colours'] ?? null ) ? $settings['colours'] : array();
        $out = array( 'enabled' => ! empty( $raw['enabled'] ), 'estate' => '10', 'scout' => '9', 'estate_groups' => array( 'HP Business', 'Estate' ) );
        foreach ( array( 'estate', 'scout' ) as $kind ) {
            $id = $raw[$kind] ?? $out[$kind];
            if ( ! is_scalar( $id ) || ! array_key_exists( (string) $id, self::colour_palette() ) ) {
                if ( $strict ) self::error( 'Select a valid Google event colour.' );
                $id = ''; // Never send an invalid provider ID from corrupt settings.
            }
            $out[$kind] = (string) $id;
        }
        if ( isset( $raw['estate_groups'] ) ) {
            $groups = is_array( $raw['estate_groups'] ) ? $raw['estate_groups'] : preg_split( '/\R/', (string) $raw['estate_groups'] );
            $out['estate_groups'] = array();
            foreach ( $groups as $group ) if ( is_string( $group ) && trim( $group ) !== '' ) $out['estate_groups'][] = trim( wp_strip_all_tags( $group ) );
            $out['estate_groups'] = array_values( array_unique( $out['estate_groups'] ) );
        }
        return $out;
    }
    /** Colour is cosmetic, never a billing/Scout-use flag or permission decision. */
    public static function booking_colour( $booking, $rules, $campsite ) {
        $b = (object) $booking;
        if ( empty( $rules['enabled'] ) ) return '';
        $group = strtolower( trim( (string) ( $b->organisation ?? '' ) ) );
        $estate = false;
        foreach ( $rules['estate_groups'] as $label ) if ( $group === strtolower( $label ) ) $estate = true;
        // Existing staff estate entries always carry this generated prefix, even with a custom organisation.
        if ( strpos( (string) ( $b->notes ?? '' ), "Estate reservation entered by staff.\n" ) === 0 ) $estate = true;
        if ( $campsite && $estate ) return $rules['estate'];
        if ( $campsite || ! empty( $b->scout_use ) ) return $rules['scout'];
        return ''; // Ordinary non-campsite paid hires retain their calendar's default.
    }
    private static function source_hash( $event ) {
        unset( $event['colorId'] ); // New-event preferences must not invalidate existing protected links.
        return hash( 'sha256', wp_json_encode( $event ) );
    }
    public static function status() {
        $s = self::settings();
        $crypto = false;
        if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) ) {
            try { $crypto = self::unseal( self::seal( 'MGF calendar encryption self-check' ) ) === 'MGF calendar encryption self-check'; } catch ( Throwable $e ) { $crypto = false; }
        }
        return array( 'secure_storage_ready' => $crypto, 'configured' => ! empty( $s['client_id'] ) && ! empty( $s['secret'] ), 'connected' => ! empty( $s['refresh'] ), 'enabled' => ! empty( $s['enabled'] ) && ( $s['policy'] ?? '' ) === 'protected-v1', 'managed_updates' => ! empty( $s['managed_updates'] ), 'policy' => ! empty( $s['managed_updates'] ) ? 'Protected estate calendar: sync MGF-created event lifecycle; never modify estate references or delete history' : 'Protected estate calendar: add only; never edit or delete Google events', 'calendar' => $s['calendar_name'] ?? '', 'calendar_id' => $s['calendar'] ?? '', 'include_pending' => ! empty( $s['pending'] ), 'last_attempt' => $s['last_attempt'] ?? '', 'last_success' => $s['last_success'] ?? '', 'last_result' => $s['last_result'] ?? ( ! empty( $s['refresh'] ) ? 'Connected. No sync has run.' : 'Not connected. No Google events have been changed.' ), 'redirect_uri' => self::redirect_uri() );
    }
    private static function redirect_uri() { return admin_url( 'admin-post.php?action=mbs_gcal_oauth_callback', 'https' ); }
    private static function page() { return admin_url( 'admin.php?page=mathlin-google-calendar' ); }
    private static function site_key() { return hash( 'sha256', untrailingslashit( home_url( '/' ) ) ); }
    private static function error( $text ) { throw new RuntimeException( $text ); }

    /** Secrets are authenticated-encrypted at rest. No plaintext fallback. */
    public static function seal( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) self::error( 'OpenSSL is required to store Google credentials securely.' );
        $iv = random_bytes( 12 ); $tag = '';
        $cipher = openssl_encrypt( $plain, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, 'MGF Google Calendar' );
        if ( $cipher === false ) self::error( 'Could not encrypt Google credentials.' );
        return base64_encode( $iv . $tag . $cipher );
    }
    public static function unseal( $sealed ) {
        $raw = base64_decode( $sealed, true );
        if ( $raw === false || strlen( $raw ) < 29 ) self::error( 'Google credentials unavailable. Reconnect the calendar.' );
        $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'MGF Google Calendar' );
        if ( $plain === false ) self::error( 'Google credentials cannot be decrypted. Reconnect after a WordPress salt change.' );
        return $plain;
    }
    private static function token_request( $fields ) {
        $s = self::settings();
        $fields['client_id'] = $s['client_id'] ?? '';
        $fields['client_secret'] = self::unseal( $s['secret'] ?? '' );
        $r = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 20, 'redirection' => 0, 'body' => $fields ) );
        if ( is_wp_error( $r ) ) self::error( 'Google authentication timed out or could not be reached. Retry or reconnect.' );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( wp_remote_retrieve_response_code( $r ) !== 200 || empty( $data['access_token'] ) ) self::error( 'Google did not authorise access. Check the OAuth client and reconnect.' );
        $s['access'] = self::seal( $data['access_token'] ); $s['expires'] = time() + max( 60, (int) ( $data['expires_in'] ?? 3600 ) ) - 60;
        if ( ! empty( $data['refresh_token'] ) ) $s['refresh'] = self::seal( $data['refresh_token'] );
        self::save( $s ); return $data;
    }
    private static function access_token() {
        $s = self::settings();
        if ( empty( $s['refresh'] ) ) self::error( 'Connect the Google account that can edit the intended shared calendar first.' );
        if ( empty( $s['access'] ) || (int) ( $s['expires'] ?? 0 ) <= time() ) {
            $d = self::token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => self::unseal( $s['refresh'] ) ) ); return $d['access_token'];
        }
        return self::unseal( $s['access'] );
    }
    private static function api( $method, $path, $body = null, $etag = '', $allow_missing = false ) {
        // Transport-level invariant, also covering old workers, forged operations and stale previews.
        if ( $method !== 'GET' && ! ( $method === 'POST' && $path === self::events_path() . '?sendUpdates=none' ) ) self::error( 'Protected calendar: existing Google events cannot be changed or deleted.' );
        $headers = array( 'Authorization' => 'Bearer ' . self::access_token(), 'Accept' => 'application/json' );
        if ( $etag ) $headers['If-Match'] = $etag;
        $args = array( 'method' => $method, 'timeout' => 20, 'redirection' => 0, 'headers' => $headers );
        if ( $body !== null ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
        $r = wp_remote_request( 'https://www.googleapis.com/calendar/v3/' . $path, $args );
        if ( is_wp_error( $r ) ) self::error( 'Google Calendar request failed or timed out. No uncertain write will be blindly repeated.' );
        $code = wp_remote_retrieve_response_code( $r );
        if ( $allow_missing && in_array( $code, array( 404, 410 ), true ) ) return null;
        if ( $code < 200 || $code >= 300 ) self::error( 'Google Calendar returned HTTP ' . $code . '. Review permissions, quota or concurrent calendar changes, then preview again.' );
        if ( $code === 204 ) return array();
        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( ! is_array( $data ) ) self::error( 'Google returned an unreadable response. Sync stopped safely.' );
        return $data;
    }
    private static function listing( $path ) {
        $items = array(); $page = ''; $seen = array();
        for ( $i = 0; $i < 50; $i++ ) {
            $r = self::api( 'GET', $path . ( strpos( $path, '?' ) === false ? '?' : '&' ) . 'maxResults=250' . ( $page ? '&pageToken=' . rawurlencode( $page ) : '' ) );
            if ( ! isset( $r['items'] ) || ! is_array( $r['items'] ) ) { if ( isset( $r['kind'] ) ) $r['items'] = array(); else self::error( 'Calendar listing incomplete; no changes made.' ); }
            $items = array_merge( $items, $r['items'] ); $page = $r['nextPageToken'] ?? '';
            if ( ! $page ) return $items;
            if ( isset( $seen[$page] ) ) self::error( 'Google repeated a page; no changes made.' );
            $seen[$page] = true;
        }
        self::error( 'Calendar listing is too large. No partial sync was applied.' );
    }
    private static function calendars() {
        return array_values( array_filter( self::listing( 'users/me/calendarList' ), function( $c ) { return in_array( $c['accessRole'] ?? '', array( 'owner', 'writer' ), true ) && empty( $c['deleted'] ); } ) );
    }
    private static function validate_calendar() {
        $s = self::settings();
        foreach ( self::calendars() as $c ) if ( ( $c['id'] ?? '' ) === ( $s['calendar'] ?? '' ) ) return $c;
        self::error( 'The selected calendar is not available with edit permission. No events changed.' );
    }
    private static function events_path() { $s = self::settings(); return 'calendars/' . rawurlencode( $s['calendar'] ?? '' ) . '/events'; }
    private static function bookings() {
        global $wpdb;
        // A complete source snapshot: cancelled/archived rows become retained-event review items.
        $table = $wpdb->prefix . MBS_TABLE;
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 20001" );
        if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 20000 ) self::error( 'Booking data could not be read completely. No calendar changes made.' );
        return $rows;
    }
    /** Managed native writes revalidate only their indexed source row. Imported
     * provenance still requires the complete graph; never cache write authority. */
    private static function booking_projection( $ref, $settings ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . ' WHERE ref=%s LIMIT 2', $ref ) );
        if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) self::error( 'Booking source could not be verified uniquely. No Google changes made.' );
        if ( $rows ) {
            $meta = json_decode( (string) ( $rows[0]->custom_fields ?? '{}' ), true ) ?: array();
            if ( isset( $meta['_mbs_legacy'] ) || isset( $meta['_mbs_whole_park_sources'] ) || isset( $meta['_mbs_multiday_sources'] ) ) $rows = self::bookings();
        }
        return self::project( $rows, ! empty( $settings['pending'] ), self::site_key(), $settings['calendar'], wp_timezone(), wp_date( 'Y-m-d' ), $settings, ! empty( get_option( 'mbs_campsite_mode', false ) ) );
    }

    /** Minimal public fields only; intentionally never exports contact/name/purpose/notes. */
    public static function project( $rows, $pending, $site, $calendar, $zone, $today, $colour_settings = array(), $campsite = false ) {
        $desired = array(); $source = array(); $legacy = array(); $imports = array(); $row_map = array(); $errors = array();
        foreach ( $rows as $row ) { $row = (object) $row; $row_map[$row->ref] = $row; }
        $colour_rules = self::colour_settings( $colour_settings );
        foreach ( $rows as $row ) {
            $b = (object) $row; $ref = (string) $b->ref;
            if ( isset( $source[$ref] ) ) self::error( 'Duplicate booking references. Resolve before syncing.' );
            $source[$ref] = (string) $b->status;
            if ( ! in_array( $b->status, $pending ? array( 'confirmed', 'paid', 'deposit_paid', 'pending' ) : array( 'confirmed', 'paid', 'deposit_paid' ), true ) ) continue;
            try {
            $meta = is_string( $b->custom_fields ?? null ) ? json_decode( $b->custom_fields, true ) : (array) ( $b->custom_fields ?? array() );
            $continuous = ( $meta['_mbs_campsite_span'] ?? '' ) === 'continuous';
            // Preserve existing event keys, titles and ownership. Only interval
            // calculation is shared; no linkage or Google event is rewritten here.
            foreach ( MBS_Booking_Intervals::intervals($b,$zone,730) as $interval ) {
                $key = $ref . '|' . ( $continuous || ! empty( $b->all_day ) ? 'stay' : $interval['day'] );
                if ( ! empty( $b->all_day ) ) {
                    $start = array( 'date' => $interval['start']->format( 'Y-m-d' ) ); $end = array( 'date' => $interval['end']->format( 'Y-m-d' ) );
                } else {
                    $a = $interval['start']; $z = $interval['end'];
                    $start = array( 'dateTime' => $a->format( DATE_RFC3339 ), 'timeZone' => $zone->getName() ); $end = array( 'dateTime' => $z->format( DATE_RFC3339 ), 'timeZone' => $zone->getName() );
                }
                $end_instant = new DateTimeImmutable( $end['date'] ?? $end['dateTime'], $zone );
                if ( $end_instant > new DateTimeImmutable( $today, $zone ) ) {
                    $label = trim( wp_strip_all_tags( (string) $b->space ) );
                    $group = trim( wp_strip_all_tags( (string) ( $b->organisation ?? '' ) ) );
                    $summary = ( $b->status === 'pending' ? 'Provisional — ' : '' ) . $label . ( $group !== '' ? ' — ' . $group : '' );
                    $desired[$key] = array( 'id' => 'mgf' . hash( 'sha256', $site . '|' . $calendar . '|' . $key ), 'summary' => function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 250 ) : $summary, 'start' => $start, 'end' => $end, 'status' => $b->status === 'pending' ? 'tentative' : 'confirmed', 'transparency' => 'opaque', 'extendedProperties' => array( 'private' => array( 'mgf_site' => $site, 'mgf_booking' => $key, 'mgf_ref' => $ref ) ) );
                    $colour = self::booking_colour( $b, $colour_rules, $campsite );
                    if ( $colour !== '' ) $desired[$key]['colorId'] = $colour;
                    $legacy[$key] = MBS_Calendar_Reference_Links::leaves( $b, $row_map );
                    $imports[$key] = isset( $meta['_mbs_legacy'] ) || isset( $meta['_mbs_whole_park_sources'] ) || isset( $meta['_mbs_multiday_sources'] );
                }
            }
            } catch ( Throwable $e ) {
                $source[$ref] = 'exception';
                $errors[$ref] = 'Invalid source dates/times. This booking needs review; unrelated bookings can sync.';
                foreach ( array_keys( $desired ) as $key ) if ( strpos( $key, $ref . '|' ) === 0 ) unset( $desired[$key] );
            }
        }
        ksort( $desired ); return array( 'desired' => $desired, 'source' => $source, 'legacy' => $legacy, 'imports' => $imports, 'errors'=>$errors, 'independent_campsite_events' => (bool) $campsite );
    }
    private static function date( $text, $zone ) {
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $text, $zone );
        if ( ! $d || $d->format( 'Y-m-d' ) !== $text ) self::error( 'Invalid booking date; sync stopped.' );
        return $d;
    }
    private static function wall_time( $day, $time, $zone ) {
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $time ) ) self::error( 'Invalid booking time; sync stopped.' );
        $text = $day->format( 'Y-m-d' ) . ' ' . ( strlen( $time ) === 5 ? $time . ':00' : $time );
        $d = new DateTimeImmutable( $text, $zone );
        if ( $d->format( 'Y-m-d H:i:s' ) !== $text ) self::error( 'Booking time falls in a daylight-saving gap. Review the booking.' );
        return $d;
    }
    private static function endpoints( $e ) {
        try {
            if ( empty( $e['start']['dateTime'] ) && empty( $e['start']['date'] ) || empty( $e['end']['dateTime'] ) && empty( $e['end']['date'] ) ) return array( false, false );
            return array( ( new DateTimeImmutable( $e['start']['dateTime'] ?? $e['start']['date'], wp_timezone() ) )->getTimestamp(), ( new DateTimeImmutable( $e['end']['dateTime'] ?? $e['end']['date'], wp_timezone() ) )->getTimestamp() );
        } catch ( Throwable $e ) { return array( false, false ); }
    }
    private static function overlaps( $a, $b ) { list( $as, $ae ) = self::endpoints( $a ); list( $bs, $be ) = self::endpoints( $b ); return $as !== false && $bs !== false && $as < $be && $bs < $ae; }
    private static function eligible( $e ) { return empty( $e['attendees'] ) && empty( $e['recurrence'] ) && empty( $e['recurringEventId'] ) && ( $e['eventType'] ?? 'default' ) === 'default' && ( $e['status'] ?? '' ) !== 'cancelled'; }
    private static function fields( $e ) { return array( $e['summary'] ?? '', self::endpoints( $e ), isset( $e['start']['date'] ), $e['status'] ?? 'confirmed', $e['transparency'] ?? 'opaque' ); }

    public static function display_event( $e ) {
        if ( ! $e ) return null;
        return array( 'summary' => $e['summary'] ?? '(untitled)', 'start' => $e['start'] ?? null, 'end' => $e['end'] ?? null, 'location' => $e['location'] ?? '' );
    }
    private static function event_hash( $e ) { return hash( 'sha256', wp_json_encode( array( self::fields( $e ), $e['etag'] ?? '', $e['location'] ?? '', $e['description'] ?? '' ) ) ); }
    /** Separate native campsite reservations have separate event identities, not time-slot identities.
     * Imported records still need provenance review; this never changes booking availability rules.
     */
    private static function independent_event( $projection, $key ) {
        return ! empty( $projection['independent_campsite_events'] ) && isset( $projection['desired'][$key] ) && empty( $projection['imports'][$key] ) && empty( $projection['legacy'][$key] );
    }
    /** Google-only decoration is not a scheduling edit. Never accept changed title/time/status/tags. */
    private static function decoration_only( $event, $link, $key, $site, $calendar ) {
        return ! empty( $link['projection'] ) && self::managed_owner( $event, $link, $key, $site, $calendar ) && self::fields( $event ) === self::fields( $link['projection'] );
    }
    private static function history() { return array_slice( (array) get_option( 'mbs_gcal_history', array() ), -100 ); }
    private static function record( $action, $ref, $result ) {
        $history = self::history();
        $history[] = array( 'at' => gmdate( DATE_RFC3339 ), 'action' => $action, 'ref' => $ref, 'result' => $result );
        update_option( 'mbs_gcal_history', array_slice( $history, -100 ), false );
    }
    public static function estate_only( $snapshot ) {
        $linked = array();
        foreach ( $snapshot['ops'] as $op ) {
            if ( $op['event'] ) $linked[$op['event']['id']] = true;
            foreach ( $op['linked_events'] ?? array() as $e ) $linked[$e['id']] = true;
        }
        return array_values( array_filter( $snapshot['events'], static function( $e ) use ( $linked ) { return ! isset( $linked[$e['id']] ) && ( $e['status'] ?? '' ) !== 'cancelled'; } ) );
    }

    /** Build an ordered, read-only plan. Unmanaged events can never be removed. */
    private static function managed_owner( $event, $link, $key, $site, $calendar ) {
        if ( ! $event || ( $link['origin'] ?? '' ) !== 'mgf' || ! self::eligible( $event ) || count( MBS_Calendar_Reference_Links::records( $link ) ) !== 1 ) return false;
        $tags = $event['extendedProperties']['private'] ?? array();
        $id = 'mgf' . hash( 'sha256', $site . '|' . $calendar . '|' . ( $link['id_key'] ?? $key ) );
        return ( $event['id'] ?? '' ) === $id && ( $link['id'] ?? '' ) === $id && ( $tags['mgf_site'] ?? '' ) === $site && ( $tags['mgf_booking'] ?? '' ) === $key && ( $tags['mgf_ref'] ?? '' ) === ( $link['ref'] ?? '' );
    }
    private static function managed_source_hash( $projection, $ref ) {
        $wanted = array(); foreach ( $projection['desired'] as $key => $event ) if ( ( $event['extendedProperties']['private']['mgf_ref'] ?? '' ) === $ref ) $wanted[$key] = $event;
        return hash( 'sha256', wp_json_encode( array( $projection['source'][$ref] ?? null, $wanted ) ) );
    }
    public static function plan( $projection, $events, $site, $today, $links = array(), $settings = array() ) {
        $owned = array(); $manual = array(); $ops = array(); $ids = array();
        foreach ( $events as $e ) {
            $ids[$e['id']] = $e; $p = $e['extendedProperties']['private'] ?? array();
            if ( ( $p['mgf_site'] ?? '' ) === $site && ! empty( $p['mgf_booking'] ) ) $owned[$p['mgf_booking']][] = $e;
            elseif ( ( $e['status'] ?? '' ) !== 'cancelled' ) $manual[] = $e;
        }
        $link_keys = array(); $aliases = array(); $rekey_reviews = array();
        if ( ! empty( $settings['managed_updates'] ) ) {
            $by_ref = array(); foreach ( $projection['desired'] as $key => $want ) $by_ref[$want['extendedProperties']['private']['mgf_ref']][] = $key;
            $owned_links = array(); foreach ( $links as $key => $link ) if ( ( $link['origin'] ?? '' ) === 'mgf' ) $owned_links[$link['ref']][] = $key;
            foreach ( $by_ref as $ref => $keys ) if ( count( $keys ) === 1 && count( $owned_links[$ref] ?? array() ) === 1 ) {
                $new_key = $keys[0]; $old_key = $owned_links[$ref][0]; $link = $links[$old_key];
                if ( $new_key !== $old_key && ! isset( $links[$new_key] ) && ! isset( $projection['desired'][$old_key] ) ) {
                    $old_event = $ids[$link['id']] ?? null;
                    if ( self::managed_owner( $old_event, $link, $old_key, $site, $settings['calendar'] ?? '' ) && ( $link['google_hash'] ?? '' ) === self::event_hash( $old_event ) ) {
                        $links[$new_key] = $link; $link_keys[$new_key] = $old_key; $aliases[$old_key] = true; unset( $owned[$old_key] );
                    } else $rekey_reviews[$new_key] = true;
                }
            }
        }
        foreach ( $projection['desired'] as $key => $want ) {
            $matches = $owned[$key] ?? array(); unset( $owned[$key] );
            $op = array( 'key' => $key, 'ref' => $want['extendedProperties']['private']['mgf_ref'], 'want' => $want, 'action' => 'create', 'event' => null, 'candidates' => array(), 'reason' => '' );
            $op['source_evidence'] = hash( 'sha256', wp_json_encode( $projection['legacy'][$key] ?? array() ) );
            $op['original_sources'] = array_values( $projection['legacy'][$key] ?? array() );
            if ( isset( $links[$key] ) ) {
                $link = $links[$key]; $records = MBS_Calendar_Reference_Links::records( $link ); $event = $ids[$link['id']] ?? null;
                $op['linked_events'] = array();
                $op['event'] = $event; $op['action'] = 'matched'; $op['reason'] = 'Protected link; Google entry is retained unchanged.';
                foreach ( $records as $id => $hash ) {
                    $e = $ids[$id] ?? null;
                    if ( ! $e || ( $e['status'] ?? '' ) === 'cancelled' ) { $op['action'] = 'blocked'; $op['reason'] = 'A linked Google area event is missing/deleted. Never recreate automatically.'; break; }
                    $op['linked_events'][] = $e;
                    if ( ! $hash || $hash !== self::event_hash( $e ) ) $op['action'] = 'difference';
                }
                if ( $op['action'] !== 'blocked' && ( empty( $link['source_hash'] ) || $link['source_hash'] !== self::source_hash( $want ) || $op['action'] === 'difference' ) ) { $op['action'] = 'difference'; $op['reason'] = 'Booking or linked estate entry changed. Google wins; review the complete linked set without overwriting it.'; }
                if ( $op['action'] === 'matched' && isset( $link['source_evidence'] ) && $link['source_evidence'] !== $op['source_evidence'] ) { $op['action'] = 'difference'; $op['reason'] = 'Original reservation evidence changed. Review every link; Google remains unchanged.'; }
                if ( ! empty( $settings['managed_updates'] ) && ( $link['origin'] ?? '' ) === 'mgf' ) {
                    $old_key = $link_keys[$key] ?? $key;
                    if ( ! self::managed_owner( $event, $link, $old_key, $site, $settings['calendar'] ?? '' ) ) { $op['action'] = 'blocked'; $op['reason'] = 'MGF ownership cannot be verified, or the event has guests/recurrence. Review without changing Google.'; }
                    elseif ( ( $link['google_hash'] ?? '' ) === self::event_hash( $event ) && ( self::fields( $want ) != self::fields( $event ) || $old_key !== $key ) ) {
                        $op['action'] = 'managed_update'; $op['reason'] = 'Update MGF-created event only; preserve estate events, colour and unrelated Google fields.';
                        $op['managed_link_key'] = $old_key; $op['managed_source_hash'] = self::managed_source_hash( $projection, $op['ref'] );
                    }
                    elseif ( $old_key === $key && self::decoration_only( $event, $link, $old_key, $site, $settings['calendar'] ?? '' ) && ( ( $link['google_hash'] ?? '' ) !== self::event_hash( $event ) || ( self::fields( $want ) === self::fields( $event ) && ( $link['source_hash'] ?? '' ) !== self::source_hash( $want ) ) ) ) {
                        $op['action'] = 'refresh_link'; $op['reason'] = 'Already linked. Preserve Google-only location, notes and colour; refresh the verified local comparison without editing Google.';
                        $op['managed_link_key'] = $old_key; $op['managed_source_hash'] = self::managed_source_hash( $projection, $op['ref'] );
                    }
                }
            }
            elseif ( count( $matches ) > 1 ) { $op['action'] = 'blocked'; $op['reason'] = 'Multiple linked events: review in Google Calendar.'; }
            elseif ( count( $matches ) === 1 ) {
                $op['event'] = $matches[0];
                if ( ! self::eligible( $matches[0] ) ) { $op['action'] = 'blocked'; $op['reason'] = 'Linked event has guests, recurrence or was deleted. Review before resuming.'; }
                elseif ( self::fields( $want ) == self::fields( $matches[0] ) ) { $op['action'] = 'matched'; $op['reason'] = 'Existing MGF event retained unchanged.'; }
                else { $op['action'] = 'difference'; $op['reason'] = 'Existing event differs. Protected mode never overwrites Google.'; }
            } elseif ( isset( $ids[$want['id']] ) ) { $op['action'] = 'blocked'; $op['reason'] = 'Reserved event ID already exists without the expected link.'; }
            else {
                foreach ( $manual as $e ) if ( self::overlaps( $want, $e ) ) $op['candidates'][] = $e;
                if ( ! empty( $projection['imports'][$key] ) || ! empty( $projection['legacy'][$key] ) || ( $op['candidates'] && ! self::independent_event( $projection, $key ) ) ) { $op['action'] = 'review'; $op['reason'] = 'Review existing Google area events. Imported reservations are never blindly added again.'; }
                elseif ( $op['candidates'] ) { $op['reason'] = 'Separate native campsite reservation: create its own Google event. Concurrent calendar entries are not duplicate identities; existing events stay unchanged.'; }
            }
            if ( isset( $rekey_reviews[$key] ) ) { $op['action'] = 'blocked'; $op['reason'] = 'The prior MGF event is missing, independently edited or no longer safely owned. Review the date move; never create a replacement blindly.'; }
            if ( $op['action'] === 'review' ) $op['recommendation'] = MBS_Calendar_Reference_Links::recommend( $projection['legacy'][$key] ?? array(), $op['candidates'], $want );
            $ops[] = $op;
        }
        foreach ( $owned as $key => $events_for_key ) foreach ( $events_for_key as $e ) {
            // Keep past history; missing source is not evidence of cancellation.
            $endpoints = self::endpoints( $e );
            if ( $endpoints[1] !== false && $endpoints[1] <= ( new DateTimeImmutable( $today, wp_timezone() ) )->getTimestamp() ) continue;
            $ref = $e['extendedProperties']['private']['mgf_ref'] ?? explode( '|', $key )[0];
            $status = $projection['source'][$ref] ?? null;
            $ops[] = array( 'key' => $key, 'ref' => $ref, 'want' => null, 'action' => 'retained', 'event' => $e, 'candidates' => array(), 'reason' => 'Booking cancelled, archived, moved or no longer exported. Google entry retained; estate review required.' );
        }
        foreach ( $links as $key => $link ) {
            if ( isset( $aliases[$key] ) || ( $link['until'] ?? '' ) < $today || isset( $projection['desired'][$key] ) || isset( $owned[$key] ) ) continue;
            $ops[] = array( 'key' => $key, 'ref' => $link['ref'], 'want' => null, 'action' => 'retained', 'event' => $ids[$link['id']] ?? null, 'linked_events' => array_values( array_intersect_key( $ids, MBS_Calendar_Reference_Links::records( $link ) ) ), 'candidates' => array(), 'reason' => 'No current exported booking. All protected estate entries retained; manual review required.' );
        }
        if ( ! empty( $settings['managed_updates'] ) ) foreach ( $ops as &$op ) {
            if ( $op['action'] !== 'retained' || ! isset( $links[$op['key']] ) ) continue;
            $link = $links[$op['key']]; $state = $projection['source'][$op['ref']] ?? null;
            if ( ! in_array( $state, array( 'cancelled','pending','confirmed','deposit_paid','paid' ), true ) || ! self::managed_owner( $op['event'], $link, $op['key'], $site, $settings['calendar'] ?? '' ) ) continue;
            if ( ( $link['google_hash'] ?? '' ) !== self::event_hash( $op['event'] ) ) { $op['action'] = 'difference'; $op['reason'] = 'MGF-created Google event was edited independently. Review; never overwrite those edits.'; continue; }
            $state = $state === 'cancelled' ? 'cancelled' : ( $state === 'pending' ? 'pending' : 'retired' );
            if ( ( $link['lifecycle'] ?? '' ) === $state ) { $op['action'] = 'matched'; $op['reason'] = 'Inactive MGF entry retained as non-blocking history.'; continue; }
            $op['action'] = 'managed_cancel'; $op['reason'] = 'Mark MGF-created entry inactive and non-blocking; preserve calendar history.'; $op['managed_lifecycle'] = $state;
            $op['managed_link_key'] = $op['key']; $op['managed_source_hash'] = self::managed_source_hash( $projection, $op['ref'] );
        } unset( $op );
        $claims = array();
        foreach ( $ops as $index => $op ) foreach ( $op['recommendation']['ids'] ?? array() as $id ) $claims[$id][] = $index;
        foreach ( $claims as $id => $indexes ) foreach ( $indexes as $a ) foreach ( $indexes as $b ) {
            if ( $a === $b ) continue;
            if ( ! MBS_Calendar_Reference_Links::can_share( MBS_Calendar_Reference_Links::descriptor( $ops[$a] ), MBS_Calendar_Reference_Links::descriptor( $ops[$b] ), $ids[$id] ) ) {
                $ops[$a]['recommendation'] = array( 'ids' => array(), 'reason' => 'Another MGF reservation claims this original Google reference with incompatible dates. Review both; Google unchanged.' );
            }
        }
        // Retire obsolete MGF slices before inserting replacements into the same interval.
        $ops = array_merge( array_values( array_filter( $ops, static function( $op ) { return $op['action'] === 'managed_cancel'; } ) ), array_values( array_filter( $ops, static function( $op ) { return $op['action'] !== 'managed_cancel'; } ) ) );
        foreach ( $ops as &$op ) $op['hash'] = hash( 'sha256', wp_json_encode( $op ) ); unset( $op );
        return $ops;
    }
    private static function snapshot() {
        self::validate_calendar(); $s = self::settings(); $today = wp_date( 'Y-m-d' );
        $projection = self::project( self::bookings(), ! empty( $s['pending'] ), self::site_key(), $s['calendar'], wp_timezone(), $today, $s, ! empty( get_option( 'mbs_campsite_mode', false ) ) );
        // Expand recurring manual events so their individual overlaps are detected.
        $midnight = new DateTimeImmutable( $today, wp_timezone() );
        $events = self::listing( self::events_path() . '?singleEvents=true&showDeleted=false&timeMin=' . rawurlencode( $midnight->format( DATE_RFC3339 ) ) . '&timeMax=' . rawurlencode( $midnight->modify( '+5 years' )->format( DATE_RFC3339 ) ) );
        $present = array_fill_keys( array_column( $events, 'id' ), true );
        // Remember protected references too. A missing estate event never silently creates a duplicate.
        foreach ( (array) ( $s['links'] ?? array() ) as $key => $link ) {
          foreach ( MBS_Calendar_Reference_Links::records( $link ) as $id => $hash ) {
            if ( isset( $present[$id] ) || ( $link['until'] ?? '' ) < $today ) continue;
            $event = self::api( 'GET', self::events_path() . '/' . rawurlencode( $id ), null, '', true );
            $tags = array( 'mgf_site' => self::site_key(), 'mgf_booking' => $key, 'mgf_ref' => $link['ref'] );
            if ( ! $event ) $event = array( 'id' => $id, 'status' => 'cancelled', 'extendedProperties' => array( 'private' => $tags ), 'end' => array( 'date' => $link['until'] ) );
            $events[] = $event;
            $present[$id] = true;
          }
        }
        $outside = array();
        foreach ( $projection['desired'] as $key => $want ) if ( substr( $want['end']['date'] ?? $want['end']['dateTime'], 0, 10 ) >= $midnight->modify( '+5 years' )->format( 'Y-m-d' ) ) { $outside[$key] = $want; unset( $projection['desired'][$key] ); }
        usort( $events, function( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
        $ops = self::plan( $projection, $events, self::site_key(), $today, (array) ( $s['links'] ?? array() ), $s );
        foreach ( $projection['errors'] ?? array() as $ref => $reason ) {
            $ops = array_values( array_filter( $ops, static function( $op ) use ( $ref ) { return $op['ref'] !== $ref; } ) );
            $ops[] = array( 'key'=>$ref.'|invalid', 'ref'=>$ref, 'want'=>null, 'event'=>null, 'candidates'=>array(), 'action'=>'blocked', 'reason'=>$reason );
        }
        $outside_refs = array();
        foreach ( $outside as $key => $want ) {
            $ref = $want['extendedProperties']['private']['mgf_ref'];
            if ( isset( $outside_refs[$ref] ) ) continue;
            $outside_refs[$ref] = true;
            // Suppress lifecycle retirement for this excluded key; it is an exception, not a cancellation.
            $ops = array_values( array_filter( $ops, static function( $op ) use ( $ref ) { return $op['ref'] !== $ref; } ) );
            $ops[] = array( 'key'=>$key, 'ref'=>$want['extendedProperties']['private']['mgf_ref'], 'want'=>$want, 'event'=>null, 'candidates'=>array(), 'action'=>'blocked', 'reason'=>'Outside the five-year comparison window. This booking is not changed; other bookings can sync.' );
        }
        return array( 'projection' => $projection, 'events' => $events, 'ops' => $ops );
    }

    /** Atomic database lease. Never steals a possibly in-flight request. */
    private static function lock() {
        global $wpdb;
        if ( self::$lease !== null || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', self::lock_key() ) ) !== 1 ) self::error( 'Another calendar operation is running.' );
        $old = get_option( 'mbs_gcal_lock' );
        if ( $old && ( $old['engine'] ?? '' ) !== 'connection-v1' ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_key() ) ); self::error( 'A legacy sync lock needs administrator review before recovery. No events changed.' ); }
        // Owning the connection lock proves a previous connection-based worker ended.
        if ( $old ) delete_option( 'mbs_gcal_lock' );
        $lease = array( 'at'=>time(), 'token'=>wp_generate_password( 32, false ), 'engine'=>'connection-v1' );
        if ( ! add_option( 'mbs_gcal_lock', $lease, '', false ) ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_key() ) ); self::error( 'Calendar lease could not be recorded.' ); }
        self::$lease = $lease;
    }
    private static function unlock() {
        global $wpdb;
        if ( self::$lease === null ) return;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name='mbs_gcal_lock' AND option_value=%s", maybe_serialize( self::$lease ) ) );
        wp_cache_delete( 'mbs_gcal_lock', 'options' );
        self::$lease = null;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_key() ) );
    }
    public static function recover_legacy_lock( $expected ) {
        if(!current_user_can('manage_options')) return new WP_Error('forbidden','Administrator required.');
        global $wpdb;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',self::lock_key()))!==1) return new WP_Error('busy','A calendar worker is active.');
        try {
            $old=get_option('mbs_gcal_lock',array());
            if(!$old||($old['engine']??'')==='connection-v1'||empty($old['at'])||time()-(int)$old['at']<3600||!hash_equals(hash('sha256',wp_json_encode($old)),$expected)) return new WP_Error('changed_lock','Lock changed, is not legacy, or is not yet one hour old. Refresh health and review.');
            $ok=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name='mbs_gcal_lock' AND option_value=%s",maybe_serialize($old)));
            wp_cache_delete('mbs_gcal_lock','options');
            return $ok===1?true:new WP_Error('changed_lock','The lock changed; nothing was recovered.');
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::lock_key())); }
    }
    private static function fingerprint( $snapshot ) { $s = self::settings(); return hash( 'sha256', wp_json_encode( array( $s['calendar'] ?? '', ! empty( $s['pending'] ), ! empty( $s['managed_updates'] ), $snapshot ) ) ); }
    private static function reviewed_choices( $ops, $input ) {
        $choices = array(); $claimed = array();
        foreach ( $ops as $item ) {
            $values = (array) ( $input[$item['hash']] ?? array() );
            $available = array_column( $item['action'] === 'difference' ? ( $item['linked_events'] ?? array_filter( array( $item['event'] ) ) ) : $item['candidates'], null, 'id' );
            foreach ( $values as $choice ) {
                if ( ! is_string( $choice ) || strpos( $choice, 'protect:' ) !== 0 || ! isset( $available[substr( $choice, 8 )] ) ) self::error( 'Invalid Google reference selection. Refresh comparison.' );
                $descriptor = MBS_Calendar_Reference_Links::descriptor( $item );
                foreach ( $claimed[$choice] ?? array() as $prior ) if ( ! MBS_Calendar_Reference_Links::can_share( $prior, $descriptor, $available[substr( $choice, 8 )] ) ) self::error( 'A Google record can be shared only by verified non-overlapping original date slices.' );
                $claimed[$choice][] = $descriptor;
            }
            if ( $values && $item['action'] === 'difference' && count( array_unique( $values ) ) !== count( $available ) ) self::error( 'Acknowledge the complete existing linked set; no area links may be silently dropped.' );
            if ( $values ) $choices[$item['hash']] = array_values( array_unique( $values ) );
        }
        if ( array_diff( array_keys( $input ), array_column( $ops, 'hash' ) ) ) self::error( 'Stale booking selection. Refresh comparison.' );
        return $choices;
    }
    /** Narrow administrator MCP parity: explicit preview/commit; never exposes descriptions or tokens. */
    public static function reviewed_sync( $input ) {
        if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) return new WP_Error( 'calendar_sync', 'Administrator review required.' );
        if ( in_array( $input['operation'] ?? '', array( 'booking_preview', 'booking_sync' ), true ) ) {
            if ( array_diff( array_keys( $input ), array( 'operation', 'ref', 'fingerprint', 'confirm' ) ) ) return new WP_Error( 'calendar_sync', 'Unknown booking calendar input.' );
            return self::booking_sync( $input['ref'] ?? '', $input['operation'] === 'booking_sync', $input['fingerprint'] ?? '', ( $input['confirm'] ?? false ) === true );
        }
        try { self::lock(); } catch ( Throwable $e ) { return new WP_Error( 'calendar_sync', $e->getMessage() ); }
        try {
            if ( array_diff( array_keys( $input ), array( 'operation','fingerprint','confirm','choices','managed_updates' ) ) ) self::error( 'Unknown calendar review input.' );
            $operation = $input['operation'] ?? 'preview';
            if ( ! in_array( $operation, array( 'preview','reconcile','enable','managed_policy' ), true ) ) self::error( 'Unknown calendar review operation.' );
            $snapshot = self::snapshot(); $fingerprint = self::fingerprint( $snapshot );
            if ( $operation !== 'preview' ) {
                if ( ( $input['confirm'] ?? false ) !== true || ! is_string( $input['fingerprint'] ?? null ) || ! hash_equals( $fingerprint, $input['fingerprint'] ) ) self::error( 'Confirm a fresh unchanged calendar comparison.' );
                if ( $operation === 'managed_policy' ) {
                    if ( ! is_bool( $input['managed_updates'] ?? null ) ) self::error( 'Choose the managed lifecycle policy explicitly.' );
                    $s = self::settings(); $s['managed_updates'] = $input['managed_updates']; $s['enabled'] = false; $s['choices'] = array(); self::save( $s );
                    return array( 'status' => self::status(), 'result' => 'Policy saved; refresh comparison before enabling. No Google event changed.' );
                }
                $choices = self::reviewed_choices( $snapshot['ops'], $input['choices'] ?? array() );
                $result = self::commit_review( $snapshot, $choices, $operation === 'enable' );
                return array( 'status' => self::status(), 'result' => $result );
            }
            $inventory = array(); foreach ( $snapshot['events'] as $event ) $inventory[$event['id']] = self::event_hash( $event );
            return array( 'status' => self::status(), 'fingerprint' => $fingerprint, 'google_inventory' => $inventory, 'items' => array_map( static function( $op ) {
                return array( 'hash' => $op['hash'], 'ref' => $op['ref'], 'key' => $op['key'], 'action' => $op['action'], 'reason' => $op['reason'], 'booking' => self::display_event( $op['want'] ), 'original_sources' => $op['original_sources'] ?? array(), 'recommendation' => $op['recommendation'] ?? null,
                    'explanation' => MBS_Calendar_Reference_Links::explain( $op ),
                    'linked_ids' => array_column( $op['linked_events'] ?? array_filter( array( $op['event'] ) ), 'id' ),
                    'candidates' => array_map( static function( $e ) { return array_merge( self::display_event( $e ), array( 'id' => $e['id'], 'legacy_id' => MBS_Calendar_Reference_Links::event_legacy_id( $e ) ) ); }, $op['candidates'] ) );
            }, $snapshot['ops'] ) );
        } catch ( Throwable $e ) { return new WP_Error( 'calendar_sync', $e->getMessage() ); }
        finally { self::unlock(); }
    }
    /** Caller holds the shared lease and has validated the fresh fingerprint/choices. */
    private static function commit_review( $snapshot, $choices, $enable ) {
        // Saving references is not permission to enable a paused/unreviewed connection.
        $resume = $enable || self::status()['enabled'];
        $s = self::settings(); $s['enabled'] = $enable ? false : $resume;
        $s['policy'] = 'protected-v1'; $s['choices'] = array(); self::save( $s );
        // Reference-only saves cannot insert or apply managed lifecycle changes.
        $result = self::execute( $snapshot['ops'], $choices, $enable );
        $s = self::settings(); $s['enabled'] = $resume;
        $s['last_attempt'] = $s['last_success'] = gmdate( DATE_RFC3339 );
        $s['last_result'] = $result['completed'] . ' completed; ' . $result['needs_review'] . ' need review; ' . $result['remaining'] . ' queued. Estate-created Google entries unchanged.';
        self::save( $s );
        self::record( $enable ? 'sync_enabled' : 'references_saved', '', $enable ? 'Protected sync enabled after review.' : ( $resume ? 'References saved only; automatic sync remains enabled.' : 'References saved only; automatic sync remains paused.' ) );
        return $result;
    }
    /** Booking-scoped projection, using the full calendar comparison for overlap safety. */
    private static function booking_ops( $snapshot, $ref ) {
        return array_values( array_filter( $snapshot['ops'], static function( $op ) use ( $ref ) { return $op['ref'] === $ref; } ) );
    }
    private static function booking_can_sync( $ops, $enabled ) {
        if ( ! $enabled || ! $ops || count( $ops ) > 20 ) return false;
        $pending = false;
        foreach ( $ops as $op ) {
            if ( ! in_array( $op['action'], array( 'matched', 'create', 'managed_update', 'managed_cancel', 'refresh_link' ), true ) ) return false;
            if ( $op['action'] !== 'matched' ) $pending = true;
        }
        return $pending;
    }
    /** Accept provider event links only, never arbitrary stored redirects. */
    public static function event_url( $event ) {
        $url = $event['htmlLink'] ?? '';
        $p = is_string( $url ) ? parse_url( $url ) : false;
        if ( ! $p || ( $p['scheme'] ?? '' ) !== 'https' || ! in_array( $p['host'] ?? '', array( 'www.google.com', 'calendar.google.com' ), true ) || strpos( $p['path'] ?? '', '/calendar/' ) !== 0 || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) ) return '';
        return $url;
    }
    private static function booking_view( $ref, $snapshot = null ) {
        $status = self::status(); $ops = $snapshot ? self::booking_ops( $snapshot, $ref ) : array();
        $labels = array( 'matched' => 'Linked / up to date', 'create' => 'Not yet synced', 'managed_update' => 'Changes waiting to sync', 'managed_cancel' => 'Cancellation/status waiting to sync', 'refresh_link' => 'Linked — comparison refresh queued (no Google edits)', 'review' => 'Needs review', 'difference' => 'Linked — changed scheduling details need review', 'blocked' => 'Blocked — needs review', 'retained' => 'Protected history — review required' );
        $items = array(); $events = array();
        foreach ( $ops as $op ) {
            $linked = $op['linked_events'] ?? array();
            if ( $op['event'] ) $linked[] = $op['event'];
            foreach ( $linked as $event ) {
                if ( empty( $event['id'] ) ) continue;
                $areas = array(); $original_id = MBS_Calendar_Reference_Links::event_legacy_id( $event );
                foreach ( $op['original_sources'] ?? array() as $source ) {
                    $source = (object) $source; $meta = json_decode( (string) ( $source->custom_fields ?? '{}' ), true );
                    if ( $original_id && (string) ( $meta['_mbs_legacy']['booking_id'] ?? '' ) === $original_id ) $areas[] = $source->space;
                }
                $dates = '';
                try {
                    if ( empty( $event['start']['date'] ) && empty( $event['start']['dateTime'] ) || empty( $event['end']['date'] ) && empty( $event['end']['dateTime'] ) ) throw new RuntimeException( 'Missing event dates.' );
                    $a = new DateTimeImmutable( $event['start']['date'] ?? $event['start']['dateTime'], wp_timezone() );
                    $z = new DateTimeImmutable( $event['end']['date'] ?? $event['end']['dateTime'], wp_timezone() );
                    if ( isset( $event['start']['date'] ) ) $dates = $a->format( 'j M Y' ) . ' – ' . $z->modify( '-1 day' )->format( 'j M Y' ) . ' · All day';
                    else $dates = $a->setTimezone( wp_timezone() )->format( 'j M Y H:i' ) . ' – ' . $z->setTimezone( wp_timezone() )->format( 'j M Y H:i' );
                } catch ( Throwable $e ) { $dates = 'Dates unavailable'; }
                $title = implode( ', ', array_unique( $areas ) );
                $events[$event['id']] = array( 'id' => $event['id'], 'summary' => ( $title ? $title . ' — ' : '' ) . ( $event['summary'] ?? 'Google event' ) . ' · ' . $dates, 'url' => self::event_url( $event ) );
            }
            $items[] = array( 'state' => $op['action'], 'label' => $labels[$op['action']] ?? 'Needs review', 'reason' => $op['reason'] );
        }
        $label = 'Not currently exported (past, unapproved, cancelled or archived booking).';
        if ( ! $status['connected'] ) $label = 'Google Calendar is not connected.';
        elseif ( ! $status['calendar_id'] ) $label = 'Select a target calendar in Calendar Sync.';
        elseif ( $items ) $label = count( $items ) === 1 ? $items[0]['label'] : count( $items ) . ' calendar entries — see status below';
        $last = null; foreach ( self::history() as $entry ) if ( ( $entry['ref'] ?? '' ) === $ref ) $last = array( 'at' => $entry['at'], 'result' => $entry['result'] );
        return array( 'ref' => $ref, 'label' => $label, 'calendar' => $status['calendar'], 'enabled' => $status['enabled'], 'checked_at' => $snapshot ? gmdate( DATE_RFC3339 ) : '', 'fingerprint' => $snapshot ? self::fingerprint( $snapshot ) : '', 'can_sync' => self::booking_can_sync( $ops, $status['enabled'] ), 'items' => $items, 'events' => array_values( $events ), 'last_action' => $last, 'review_url' => self::page() );
    }
    public static function booking_sync( $ref, $write = false, $fingerprint = '', $confirmed = false ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'booking_calendar', 'Administrator access required.' );
        if ( ! is_string( $ref ) || ! preg_match( '/^[A-Z0-9-]{1,100}$/D', $ref ) ) return new WP_Error( 'booking_calendar', 'Invalid booking reference.' );
        try { self::lock(); } catch ( Throwable $e ) { return new WP_Error( 'booking_calendar', $e->getMessage() ); }
        try {
            $exists = false; foreach ( self::bookings() as $booking ) if ( $booking->ref === $ref ) $exists = true;
            if ( ! $exists ) self::error( 'Booking not found.' );
            $status = self::status();
            if ( ! $status['connected'] || ! $status['calendar_id'] ) {
                if ( $write ) self::error( 'Connect and select a calendar before syncing.' );
                return self::booking_view( $ref );
            }
            $snapshot = self::snapshot();
            if ( $write ) {
                if ( ! $confirmed || ! is_string( $fingerprint ) || ! hash_equals( self::fingerprint( $snapshot ), $fingerprint ) ) self::error( 'Booking or calendar changed. Check status again before syncing.' );
                $ops = self::booking_ops( $snapshot, $ref );
                if ( ! self::booking_can_sync( $ops, self::status()['enabled'] ) ) self::error( 'This booking cannot sync automatically. Check Calendar Sync review or enable the reviewed connection first.' );
                // No choices, global enablement or unrelated operations are accepted here.
                $result = self::execute( $ops, array(), true );
                self::record( 'booking_sync', $ref, $result['completed'] . ' event changes for this booking only.' . ( empty( $result['refreshed'] ) ? '' : ' ' . $result['refreshed'] . ' local link refresh; no Google edit.' ) );
                $snapshot = self::snapshot();
            }
            return self::booking_view( $ref, $snapshot );
        } catch ( Throwable $e ) { return new WP_Error( 'booking_calendar', $e->getMessage() ); }
        finally { self::unlock(); }
    }
    public function booking_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Administrator access required.', 403 );
        check_ajax_referer( 'mbs_gcal_booking', 'nonce' );
        $operation = $_POST['operation'] ?? 'preview';
        if ( ! in_array( $operation, array( 'preview', 'sync' ), true ) ) wp_send_json_error( 'Invalid operation.', 400 );
        $result = self::booking_sync( wp_unslash( $_POST['ref'] ?? '' ), $operation === 'sync', wp_unslash( $_POST['fingerprint'] ?? '' ), ( $_POST['confirm'] ?? '' ) === '1' );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), 409 );
        wp_send_json_success( $result );
    }
    public static function render_booking_card( $booking ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = self::settings();
        if ( empty( $s['client_id'] ) && empty( $s['refresh'] ) && empty( $s['calendar'] ) ) return;
        include MBS_PLUGIN_DIR . 'admin/views/booking-calendar.php';
    }
    private static function execute( $ops, $choices = array(), $allow_create = true ) {
        $done = 0; $refreshed = 0; $blocked = 0; $checked = 0; $used = array();
        $initial_settings = self::settings();
        $current_projection = self::project( self::bookings(), ! empty( $initial_settings['pending'] ), self::site_key(), $initial_settings['calendar'], wp_timezone(), wp_date( 'Y-m-d' ), $initial_settings, ! empty( get_option( 'mbs_campsite_mode', false ) ) );
        foreach ( (array) ( $initial_settings['links'] ?? array() ) as $key => $link ) {
            $descriptor = array( 'key' => $key );
            if ( isset( $current_projection['desired'][$key] ) && ( $link['source_hash'] ?? '' ) === self::source_hash( $current_projection['desired'][$key] ) && ( $link['source_evidence'] ?? '' ) === hash( 'sha256', wp_json_encode( $current_projection['legacy'][$key] ?? array() ) ) ) $descriptor = MBS_Calendar_Reference_Links::descriptor( array( 'key' => $key, 'want' => $current_projection['desired'][$key], 'original_sources' => $current_projection['legacy'][$key] ?? array() ) );
            foreach ( MBS_Calendar_Reference_Links::records( $link ) as $id => $hash ) $used[$id][] = $descriptor;
        }
        foreach ( $ops as $op ) {
            if ( $allow_create && $done + $refreshed >= 20 ) break;
            $checked++;
            $action = $op['action']; $event = $op['event'];
            if ( $action === 'refresh_link' ) {
                if ( ! $allow_create ) continue;
                self::refresh_link( $op ); $refreshed++; continue;
            }
            if ( in_array( $action, array( 'managed_update','managed_cancel' ), true ) ) {
                if ( ! $allow_create ) continue; // Saving references never changes Google.
                self::execute_managed( $op ); $done++; continue;
            }
            $selected = array();
            if ( $action === 'difference' && $event && isset( $choices[$op['hash']] ) ) { $action = 'review'; $op['candidates'] = $op['linked_events'] ?? array( $event ); }
            if ( $action === 'matched' ) continue;
            if ( ! in_array( $action, array( 'create', 'review' ), true ) ) { $blocked++; continue; }
            if ( $action === 'review' ) {
                $choices_for_op = (array) ( $choices[$op['hash']] ?? array() );
                foreach ( $choices_for_op as $choice ) {
                    if ( ! is_string( $choice ) || strpos( $choice, 'protect:' ) !== 0 ) continue;
                    foreach ( $op['candidates'] as $candidate ) if ( $candidate['id'] === substr( $choice, 8 ) && ( $candidate['status'] ?? '' ) !== 'cancelled' ) {
                        $compatible = true;
                        foreach ( $used[$candidate['id']] ?? array() as $prior ) if ( ! MBS_Calendar_Reference_Links::can_share( $prior, MBS_Calendar_Reference_Links::descriptor( $op ), $candidate ) ) $compatible = false;
                        if ( $compatible ) $selected[$candidate['id']] = $candidate;
                    }
                }
                if ( ! $selected || count( $selected ) !== count( array_unique( $choices_for_op ) ) ) { $blocked++; continue; }
                $action = 'protect'; $event = reset( $selected );
            }
            if ( $action === 'create' && ! $allow_create ) continue;
            // Revalidate changeable booking facts immediately before each write.
            $s = self::settings();
            $fresh = self::project( self::bookings(), ! empty( $s['pending'] ), self::site_key(), $s['calendar'], wp_timezone(), wp_date( 'Y-m-d' ), $s, ! empty( get_option( 'mbs_campsite_mode', false ) ) );
            if ( $op['want'] !== ( $fresh['desired'][$op['key']] ?? null ) ) self::error( 'A booking changed during sync. Preview again; already completed operations are retained.' );
            if ( isset( $op['source_evidence'] ) && $op['source_evidence'] !== hash( 'sha256', wp_json_encode( $fresh['legacy'][$op['key']] ?? array() ) ) ) self::error( 'Original area provenance changed during review. Refresh comparison.' );
            if ( $action === 'protect' ) {
              foreach ( $selected as $event ) {
                $current = self::api( 'GET', self::events_path() . '/' . rawurlencode( $event['id'] ), null, '', true );
                if ( ! $current || self::event_hash( $current ) !== self::event_hash( $event ) ) self::error( 'Estate event changed. Refresh the read-only comparison before saving a match.' );
                $used[$event['id']][] = MBS_Calendar_Reference_Links::descriptor( $op );
              }
            } else {
                // Recheck immediately before insertion; independent native campsite events may coexist.
                list( $start_at, $end_at ) = self::endpoints( $op['want'] );
                $nearby = self::listing( self::events_path() . '?singleEvents=true&showDeleted=false&timeMin=' . rawurlencode( gmdate( DATE_RFC3339, $start_at ) ) . '&timeMax=' . rawurlencode( gmdate( DATE_RFC3339, $end_at ) ) );
                foreach ( $nearby as $existing ) if ( self::overlaps( $op['want'], $existing ) && ! self::inactive_owned( $existing, $s ) && ( ! self::independent_event( $fresh, $op['key'] ) || ( $existing['id'] ?? '' ) === $op['want']['id'] || ( $existing['extendedProperties']['private']['mgf_ref'] ?? '' ) === $op['ref'] ) ) self::error( 'An overlapping event may be the same booking. Refresh comparison; no duplicate was created.' );
                // Stable IDs make a timed-out insert safe: a new preview finds the tagged event.
                $body = $op['want']; $body['reminders'] = array( 'useDefault' => false );
                $event = self::api( 'POST', self::events_path() . '?sendUpdates=none', $body );
                if ( ( $event['id'] ?? '' ) !== $body['id'] || empty( $event['etag'] ) ) self::error( 'Google insert response was incomplete. Refresh preview; do not blindly create again.' );
            }
            $done++;
            $s = self::settings(); $s['has_written'] = ! empty( $s['has_written'] ) || $action === 'create';
            $s['links'][$op['key']] = array( 'id' => $event['id'], 'ref' => $op['ref'], 'origin' => $action === 'protect' ? 'estate' : 'mgf', 'source_hash' => self::source_hash( $op['want'] ), 'google_hash' => self::event_hash( $event ), 'until' => substr( $op['want']['end']['date'] ?? $op['want']['end']['dateTime'], 0, 10 ) );
            if ( $action === 'protect' ) { $s['links'][$op['key']]['events'] = array(); foreach ( $selected as $e ) $s['links'][$op['key']]['events'][$e['id']] = self::event_hash( $e ); }
            if ( $action === 'protect' ) $s['links'][$op['key']]['source_evidence'] = $op['source_evidence'];
            if ( $action === 'protect' ) $s['links'][$op['key']]['reference_source'] = MBS_Calendar_Reference_Links::descriptor( $op );
            if ( $action === 'create' ) { $s['links'][$op['key']]['projection'] = $op['want']; $s['links'][$op['key']]['lifecycle'] = 'active'; $s['links'][$op['key']]['id_key'] = $op['key']; }
            self::save( $s );
            self::record( $action, $op['ref'], $action === 'protect' ? 'Protected reference saved; Google unchanged.' : 'Created one MGF event; existing estate events unchanged.' );
        }
        return array( 'completed' => $done, 'refreshed' => $refreshed, 'needs_review' => $blocked, 'remaining' => max( 0, count( $ops ) - $checked ) );
    }
    private static function inactive_owned( $event, $settings ) {
        $key = $event['extendedProperties']['private']['mgf_booking'] ?? ''; $link = $settings['links'][$key] ?? array();
        return in_array( $link['lifecycle'] ?? '', array( 'cancelled','pending','retired' ), true ) && ( $event['transparency'] ?? '' ) === 'transparent' && self::managed_owner( $event, $link, $key, self::site_key(), $settings['calendar'] ?? '' ) && ( $link['google_hash'] ?? '' ) === self::event_hash( $event );
    }
    /** Rebaseline only a provably MGF-owned event whose scheduling fields have not changed.
     * There is deliberately no provider write here, and origin remains mgf (not estate).
     */
    private static function refresh_link( $op ) {
        $s = self::settings(); $key = $op['managed_link_key'] ?? ''; $link = $s['links'][$key] ?? array();
        if ( empty( $s['managed_updates'] ) || ( $s['policy'] ?? '' ) !== 'protected-v1' || $key !== $op['key'] ) self::error( 'Managed comparison refresh is not authorised.' );
        $fresh = self::booking_projection( $op['ref'], $s );
        if ( ( $op['managed_source_hash'] ?? '' ) !== self::managed_source_hash( $fresh, $op['ref'] ) ) self::error( 'Booking changed before comparison refresh.' );
        $event = self::api( 'GET', self::events_path() . '/' . rawurlencode( $link['id'] ?? '' ), null, '', true );
        if ( ! self::decoration_only( $event, $link, $key, self::site_key(), $s['calendar'] ) || empty( $event['etag'] ) || self::event_hash( $event ) !== self::event_hash( $op['event'] ) ) self::error( 'Google scheduling details or ownership changed. Review again; no event was modified.' );
        $latest = self::booking_projection( $op['ref'], $s );
        if ( $op['managed_source_hash'] !== self::managed_source_hash( $latest, $op['ref'] ) ) self::error( 'Booking changed during comparison refresh.' );
        $link['google_hash'] = self::event_hash( $event );
        // If MGF also changed, retain its old projection so the next pass performs a guarded PATCH.
        $want = $latest['desired'][$key];
        if ( self::fields( $want ) === self::fields( $event ) ) { $link['source_hash'] = self::source_hash( $want ); $link['projection'] = $want; }
        $s['links'][$key] = $link; self::save( $s );
        self::record( 'refresh_link', $op['ref'], 'Verified existing MGF link refreshed locally; Google location, notes, colour and event identity preserved. No Google edit.' );
    }
    /** The only PATCH path. Requires local create receipt, exact tags/ID, unchanged baseline and source. Never DELETE. */
    private static function execute_managed( $op ) {
        $s = self::settings(); $old_key = $op['managed_link_key'] ?? ''; $link = $s['links'][$old_key] ?? array();
        if ( empty( $s['managed_updates'] ) || ( $s['policy'] ?? '' ) !== 'protected-v1' || ( $link['origin'] ?? '' ) !== 'mgf' ) self::error( 'Managed updates are disabled or ownership receipt is missing.' );
        $fresh = self::booking_projection( $op['ref'], $s );
        if ( ( $op['managed_source_hash'] ?? '' ) !== self::managed_source_hash( $fresh, $op['ref'] ) ) self::error( 'Booking changed before managed update. Review again.' );
        $event = self::api( 'GET', self::events_path() . '/' . rawurlencode( $link['id'] ?? '' ), null, '', true );
        if ( ! self::managed_owner( $event, $link, $old_key, self::site_key(), $s['calendar'] ) || empty( $event['etag'] ) || ( $link['google_hash'] ?? '' ) !== self::event_hash( $event ) || self::event_hash( $event ) !== self::event_hash( $op['event'] ) ) self::error( 'Google event changed or ownership is unverified. No event was overwritten.' );
        $body = array();
        if ( $op['action'] === 'managed_update' ) {
            $want = $fresh['desired'][$op['key']] ?? null;
            if ( ! $want || $want !== $op['want'] ) self::error( 'Managed booking projection changed.' );
            list( $a, $z ) = self::endpoints( $want );
            $nearby = self::listing( self::events_path() . '?singleEvents=true&showDeleted=false&timeMin=' . rawurlencode( gmdate( DATE_RFC3339, $a ) ) . '&timeMax=' . rawurlencode( gmdate( DATE_RFC3339, $z ) ) );
            foreach ( $nearby as $other ) if ( ( $other['id'] ?? '' ) !== $event['id'] && self::overlaps( $want, $other ) && ! self::inactive_owned( $other, $s ) && ( ! self::independent_event( $fresh, $op['key'] ) || ( $other['extendedProperties']['private']['mgf_ref'] ?? '' ) === $op['ref'] ) ) self::error( 'Changed booking overlaps another Google entry. Review before updating.' );
            foreach ( array( 'summary','start','end','status','transparency' ) as $field ) $body[$field] = $want[$field];
            // Clear the opposite endpoint type when changing between all-day and timed.
            foreach ( array( 'start','end' ) as $field ) { if ( isset( $body[$field]['date'] ) ) { $body[$field]['dateTime'] = null; $body[$field]['timeZone'] = null; } else $body[$field]['date'] = null; }
            $body['extendedProperties'] = $event['extendedProperties'] ?? array();
            $body['extendedProperties']['private'] = array_merge( $body['extendedProperties']['private'] ?? array(), $want['extendedProperties']['private'] );
        } elseif ( $op['action'] === 'managed_cancel' ) {
            $state = $fresh['source'][$op['ref']] ?? null;
            if ( $state === null || $state === 'archived' ) self::error( 'Missing or archived source is not authority to cancel a calendar event.' );
            $prefix = array( 'cancelled' => 'Cancelled — ', 'pending' => 'Not confirmed — ', 'retired' => 'Superseded — ' )[$op['managed_lifecycle']] ?? null;
            if ( ! $prefix ) self::error( 'Unknown managed lifecycle.' );
            $title = $link['projection']['summary'] ?? preg_replace( '/^(Cancelled|Not confirmed|Superseded) — /u', '', $event['summary'] ?? '' );
            $body = array( 'summary' => $prefix . $title, 'transparency' => 'transparent', 'status' => 'confirmed' );
        } else self::error( 'Unsupported managed operation.' );
        $latest = self::booking_projection( $op['ref'], $s );
        if ( $op['managed_source_hash'] !== self::managed_source_hash( $latest, $op['ref'] ) ) self::error( 'Booking changed during managed update. Nothing sent.' );
        $response = wp_remote_request( 'https://www.googleapis.com/calendar/v3/' . self::events_path() . '/' . rawurlencode( $event['id'] ) . '?sendUpdates=none', array( 'method' => 'PATCH', 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . self::access_token(), 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'If-Match' => $event['etag'] ), 'body' => wp_json_encode( $body ) ) );
        if ( is_wp_error( $response ) ) self::error( 'Managed update outcome uncertain. Refresh comparison before retrying.' );
        $code = wp_remote_retrieve_response_code( $response ); $updated = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $updated ) || ( $updated['id'] ?? '' ) !== $event['id'] || empty( $updated['etag'] ) ) self::error( 'Managed update returned HTTP ' . $code . ' or incomplete evidence. Refresh; do not retry blindly.' );
        foreach ( $body as $field => $value ) {
            if ( in_array( $field, array( 'start','end' ), true ) ) { if ( self::endpoints( $updated ) !== self::endpoints( $want ) || isset( $updated['start']['date'] ) !== isset( $want['start']['date'] ) ) self::error( 'Google endpoint acknowledgement differs. Review update.' ); }
            elseif ( ( $updated[$field] ?? null ) != $value ) self::error( 'Google update acknowledgement differs. Review before retrying.' );
        }
        $s = self::settings();
        $link['google_hash'] = self::event_hash( $updated ); $link['id_key'] = $link['id_key'] ?? $old_key;
        $link['lifecycle'] = $op['action'] === 'managed_update' ? 'active' : $op['managed_lifecycle'];
        if ( $op['action'] === 'managed_update' ) { $link['projection'] = $op['want']; $link['source_hash'] = self::source_hash( $op['want'] ); $link['until'] = substr( $op['want']['end']['date'] ?? $op['want']['end']['dateTime'], 0, 10 ); }
        elseif ( empty( $link['projection'] ) ) $link['projection'] = array( 'summary' => $event['summary'] ?? '', 'start' => $event['start'], 'end' => $event['end'] );
        if ( $old_key !== $op['key'] ) unset( $s['links'][$old_key] );
        $s['links'][$op['key']] = $link; self::save( $s );
        self::record( $op['action'], $op['ref'], $op['action'] === 'managed_update' ? 'MGF-created event updated with version guard; estate events unchanged.' : 'MGF-created event labelled inactive and non-blocking; history retained.' );
    }
    public function tick() {
        $s = self::settings(); if ( empty( $s['enabled'] ) || empty( $s['refresh'] ) || empty( $s['calendar'] ) || ( $s['policy'] ?? '' ) !== 'protected-v1' ) return;
        try { self::lock(); } catch ( Throwable $e ) { return; }
        try {
            $s = self::settings(); $s['last_attempt'] = gmdate( DATE_RFC3339 ); self::save( $s );
            $snapshot = self::snapshot();
            $result = self::execute( $snapshot['ops'], (array) ( $s['choices'] ?? array() ) );
            $s = self::settings(); $s['last_success'] = gmdate( DATE_RFC3339 ); $s['last_result'] = $result['completed'] . ' event changes; ' . $result['needs_review'] . ' need review; ' . $result['remaining'] . ' queued. Past history and unrelated events retained.' . ( empty( $result['refreshed'] ) ? '' : ' ' . $result['refreshed'] . ' local link refresh; no Google edit.' ); self::save( $s );
        } catch ( Throwable $e ) { $s = self::settings(); $s['last_result'] = $e->getMessage(); self::save( $s ); self::record( 'stopped', '', 'Sync stopped safely. See connection status; no estate entries changed.' ); }
        finally { self::unlock(); }
    }
    private static function notice( $text ) { set_transient( 'mbs_gcal_notice_' . get_current_user_id(), $text, 300 ); }
    private static function bounce() { wp_safe_redirect( self::page() ); exit; }
    public function handle() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator access required.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mbs_gcal' );
        try { self::lock(); } catch ( Throwable $e ) { self::notice( $e->getMessage() ); self::bounce(); }
        $redirect = '';
        try {
            $s = self::settings(); $op = sanitize_key( $_POST['operation'] ?? '' );
            if ( $op === 'credentials' ) {
                $id = trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
                $secret = trim( wp_unslash( $_POST['client_secret'] ?? '' ) );
                if ( ! preg_match( '/^[a-zA-Z0-9_.-]+\.apps\.googleusercontent\.com$/', $id ) ) self::error( 'Enter a Google Web application OAuth client ID.' );
                if ( ! $secret && ( empty( $s['secret'] ) || ( $s['client_id'] ?? '' ) !== $id ) ) self::error( 'Enter the OAuth client secret in this settings page, not in chat.' );
                if ( $secret ) $s['secret'] = self::seal( $secret );
                $s['client_id'] = $id; $s['enabled'] = false; unset( $s['refresh'], $s['access'], $s['expires'], $s['choices'] ); self::save( $s );
                self::notice( 'Credentials saved securely. Connect the Google account next. Synchronisation remains off.' );
            } elseif ( $op === 'connect' ) {
                if ( empty( $s['client_id'] ) || empty( $s['secret'] ) ) self::error( 'Save OAuth credentials first.' );
                $s['enabled'] = false; self::save( $s );
                $state = bin2hex( random_bytes( 32 ) ); $verifier = bin2hex( random_bytes( 32 ) );
                set_transient( 'mbs_gcal_oauth_' . hash( 'sha256', $state ), array( 'user' => get_current_user_id(), 'client' => $s['client_id'], 'verifier' => $verifier ), 900 );
                $redirect = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array( 'client_id' => $s['client_id'], 'redirect_uri' => self::redirect_uri(), 'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => $state, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), 'code_challenge_method' => 'S256' ), '', '&', PHP_QUERY_RFC3986 );
            } elseif ( $op === 'target' ) {
                $id = sanitize_text_field( wp_unslash( $_POST['calendar'] ?? '' ) ); $found = null;
                foreach ( self::calendars() as $c ) if ( $c['id'] === $id ) $found = $c;
                if ( ! $found ) self::error( 'Select a calendar with writer or owner permission.' );
                if ( ( ! empty( $s['has_written'] ) || ! empty( $s['links'] ) ) && $id !== ( $s['calendar'] ?? '' ) ) self::error( 'This connection has existing links. Retargeting requires a separate migration review.' );
                $s['calendar'] = $id; $s['calendar_name'] = $found['summary'] ?? $id; $s['pending'] = ! empty( $_POST['pending'] ); $s['enabled'] = false; $s['choices'] = array(); self::save( $s );
                self::notice( 'Target saved. Preview and review before enabling sync.' );
            } elseif ( $op === 'colours' ) {
                $s['colours'] = self::colour_settings( array( 'colours' => wp_unslash( $_POST['colours'] ?? array() ) ), true );
                self::save( $s );
                delete_transient( 'mbs_gcal_preview_' . get_current_user_id() );
                self::notice( 'New-event colours saved. Existing Google colours and sync enabled/paused state are unchanged.' );
            } elseif ( $op === 'preview' ) {
                $snapshot = self::snapshot();
                set_transient( 'mbs_gcal_preview_' . get_current_user_id(), array( 'fingerprint' => self::fingerprint( $snapshot ), 'snapshot' => $snapshot ), 900 );
                self::notice( 'Read-only preview ready. Nothing has been written to Google.' );
            } elseif ( $op === 'lifecycle' ) {
                $s['managed_updates'] = ! empty( $_POST['managed_updates'] ); $s['enabled'] = false; $s['choices'] = array(); self::save( $s );
                delete_transient( 'mbs_gcal_preview_' . get_current_user_id() ); self::notice( 'MGF-created lifecycle policy saved. Existing estate references stay protected. Refresh comparison and enable sync.' );
            } elseif ( $op === 'apply' || $op === 'reconcile' ) {
                if ( empty( $_POST['confirm'] ) ) self::error( 'Confirm the selected calendar and review before enabling.' );
                $preview = get_transient( 'mbs_gcal_preview_' . get_current_user_id() );
                $snapshot = self::snapshot();
                if ( ! $preview || ! hash_equals( $preview['fingerprint'], self::fingerprint( $snapshot ) ) ) self::error( 'The preview expired or booking/calendar data changed. Generate a fresh preview.' );
                $choices = self::reviewed_choices( $snapshot['ops'], wp_unslash( $_POST['choice'] ?? array() ) );
                $result = self::commit_review( $snapshot, $choices, $op === 'apply' );
                $s = self::settings();
                delete_transient( 'mbs_gcal_preview_' . get_current_user_id() ); self::notice( $s['last_result'] . ( $op === 'apply' ? ' Protected sync enabled with the selected lifecycle policy.' : ( self::status()['enabled'] ? ' Matches saved; automatic sync remains enabled.' : ' Matches saved; automatic sync remains paused.' ) ) );
            } elseif ( $op === 'pause' || $op === 'disconnect' ) {
                $s['enabled'] = false;
                if ( $op === 'disconnect' ) { unset( $s['refresh'], $s['access'], $s['expires'], $s['choices'] ); }
                self::save( $s ); delete_transient( 'mbs_gcal_preview_' . get_current_user_id() );
                self::notice( $op === 'pause' ? 'Sync paused. Calendar events retained.' : 'Google tokens removed from this site. Existing events retained. You can also revoke the app in your Google account permissions.' );
            } else self::error( 'Unknown calendar operation.' );
        } catch ( Throwable $e ) { self::notice( $e->getMessage() ); }
        finally { self::unlock(); }
        if ( $redirect ) { wp_redirect( $redirect ); exit; } // Fixed Google origin only, constructed above.
        self::bounce();
    }
    public function callback() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sign in as the connecting WordPress administrator and reconnect.' );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        $key = 'mbs_gcal_oauth_' . hash( 'sha256', $state ); $saved = get_transient( $key ); delete_transient( $key );
        $s = self::settings();
        if ( ! $saved || $saved['user'] !== get_current_user_id() || $saved['client'] !== ( $s['client_id'] ?? '' ) ) { self::notice( 'OAuth state expired or did not match this administrator. Reconnect.' ); self::bounce(); }
        try { self::lock(); } catch ( Throwable $e ) { self::notice( $e->getMessage() ); self::bounce(); }
        try {
            if ( ! empty( $_GET['error'] ) || empty( $_GET['code'] ) ) self::error( 'Google connection was declined or incomplete. No calendar events changed.' );
            $d = self::token_request( array( 'grant_type' => 'authorization_code', 'code' => sanitize_text_field( wp_unslash( $_GET['code'] ) ), 'redirect_uri' => self::redirect_uri(), 'code_verifier' => $saved['verifier'] ) );
            $granted = explode( ' ', $d['scope'] ?? '' );
            foreach ( explode( ' ', self::SCOPES ) as $scope ) if ( ! in_array( $scope, $granted, true ) ) self::error( 'Google did not grant both required permissions. Reconnect and grant calendar access.' );
            $s = self::settings(); $s['enabled'] = false; self::save( $s );
            if ( empty( $s['refresh'] ) ) self::error( 'No offline token was returned. Reconnect with consent.' );
            self::notice( 'Connected. Select the intended existing shared calendar, then preview. Sync is still off.' );
        } catch ( Throwable $e ) { $s = self::settings(); $s['enabled'] = false; unset( $s['refresh'], $s['access'] ); self::save( $s ); self::notice( $e->getMessage() ); }
        finally { self::unlock(); }
        self::bounce();
    }
    private static function form_start( $operation ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mbs_gcal"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'mbs_gcal' ); }
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = self::settings(); $status = self::status(); $notice = get_transient( 'mbs_gcal_notice_' . get_current_user_id() ); delete_transient( 'mbs_gcal_notice_' . get_current_user_id() );
        $calendars = array(); $problem = '';
        if ( $status['connected'] ) try { $calendars = self::calendars(); } catch ( Throwable $e ) { $problem = $e->getMessage(); }
        $preview = get_transient( 'mbs_gcal_preview_' . get_current_user_id() );
        include MBS_PLUGIN_DIR . 'admin/views/google-calendar.php';
    }
}
