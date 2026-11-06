<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit invoice agreements. Never infer credit from login, tier or BACS. */
class MBS_Credit_Accounts {
    public static function table( $assignments = false ) {
        global $wpdb;
        return $wpdb->prefix . ( $assignments ? 'mathlin_credit_assignments' : 'mathlin_credit_accounts' );
    }

    public static function schemas() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $accounts = self::table(); $assignments = self::table( true );
        return array(
            $accounts => "CREATE TABLE {$accounts} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                version BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                agreement_json LONGTEXT NOT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY status (status)
            ) {$charset};",
            $assignments => "CREATE TABLE {$assignments} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                version BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
                scope_kind VARCHAR(10) NOT NULL,
                scope_ref VARCHAR(30) NOT NULL,
                account_id BIGINT(20) UNSIGNED NOT NULL,
                contact_email VARCHAR(254) NOT NULL,
                reviewed_by BIGINT(20) UNSIGNED NOT NULL,
                reason TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY scope (scope_kind,scope_ref),
                KEY account_id (account_id)
            ) {$charset};",
        );
    }

    public static function supported() {
        return MBS_Payment_Features::enabled( 'payments' ) && ! MBS_Campsite_Policy::enabled();
    }
    public static function enabled() {
        return self::supported() && (bool) get_option( 'mbs_credit_accounts_enabled', false );
    }
    public static function init() {
        add_action( 'admin_post_mbs_credit_accounts', array( __CLASS__, 'admin_action' ) );
        add_action( 'admin_menu', static function() {
            add_submenu_page( null, 'Approved invoice accounts', 'Invoice accounts', 'manage_options', 'mathlin-credit-accounts', array( __CLASS__, 'page' ) );
        } );
    }
    public static function account( $id, $lock = false ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d' . ( $lock ? ' FOR UPDATE' : '' ), $id ) );
    }
    public static function assignment( $kind, $ref ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( true ) . ' WHERE scope_kind=%s AND scope_ref=%s', $kind, $ref ) );
        // Unknown is not proof of ordinary billing: prevent cancellation or
        // unpaid access when the agreement registry cannot be read.
        return $wpdb->last_error ? (object) array( 'id' => 0, 'version' => 0, 'account_id' => 0, 'contact_email' => '', 'unavailable' => true ) : $row;
    }
    /** A retained assignment never silently rearms a legacy cancellation deadline. */
    public static function for_booking( $b ) {
        return self::supported() ? self::assignment( empty( $b->series_id ) ? 'booking' : 'series', empty( $b->series_id ) ? $b->ref : $b->series_id ) : null;
    }
    public static function validate_agreement( $input ) {
        $name = sanitize_text_field( $input['name'] ?? '' );
        $raw = preg_split( '/[\s,;]+/', trim( (string) ( $input['contacts'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        if ( $name === '' || strlen( $name ) > 200 || ! $raw || count( $raw ) > 30 ) return new WP_Error( 'credit_agreement', 'Enter an organisation and 1–30 authorised email addresses.' );
        $contacts = array();
        foreach ( $raw as $email ) {
            if ( ! is_email( $email ) || strlen( $email ) > 254 ) return new WP_Error( 'credit_contact', 'Every authorised contact must be a valid email address.' );
            $contacts[] = strtolower( $email );
        }
        $days = (string) ( $input['terms_days'] ?? '' );
        $until = (string) ( $input['valid_until'] ?? '' );
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $until, wp_timezone() );
        if ( ! ctype_digit( $days ) || (int) $days < 1 || (int) $days > 90 || ! $date || $date->format( 'Y-m-d' ) !== $until || $until < wp_date( 'Y-m-d' ) ) return new WP_Error( 'credit_terms', 'Choose 1–90 invoice days and a valid agreement expiry date, today or later.' );
        return array( 'name' => $name, 'contacts' => array_values( array_unique( $contacts ) ), 'terms_days' => (int) $days, 'valid_until' => $until, 'allow_unpaid_access' => ! empty( $input['allow_unpaid_access'] ) );
    }
    /** Pure identity/date check, also used immediately before queued access delivery. */
    public static function permits( $agreement, $assignment, $email, $date, $today, $access = false ) {
        if ( ! is_array( $agreement ) || ! is_array( $agreement['contacts'] ?? null ) || empty( $agreement['valid_until'] ) || empty( $agreement['terms_days'] ) ) return false;
        $email = strtolower( trim( (string) $email ) );
        return $email !== '' && $email === strtolower( (string) $assignment->contact_email )
            && in_array( $email, $agreement['contacts'], true )
            && $today <= $agreement['valid_until'] && $date <= $agreement['valid_until']
            && ( ! $access || ! empty( $agreement['allow_unpaid_access'] ) );
    }
    public static function context( $b, $access = false, $lock = false ) {
        if ( ! self::enabled() ) return null;
        $link = self::for_booking( $b );
        if ( ! $link ) return null;
        $account = self::account( $link->account_id, $lock );
        $agreement = $account ? json_decode( $account->agreement_json, true ) : null;
        $last_date = ! empty( $b->booking_date_end ) ? $b->booking_date_end : $b->booking_date;
        if ( ! $account || $account->status !== 'active' || ! self::permits( $agreement, $link, $b->email, $last_date, wp_date( 'Y-m-d' ), $access ) ) return null;
        if ( ! empty( $b->series_id ) ) {
            $series = MBS_Series::get( $b->series_id );
            if ( ! $series || ! in_array( $series->status, array( 'pending','confirmed' ), true ) || $series->billing_treatment !== 'invoice_managed'
                || (int) $series->payment_terms_days !== (int) $agreement['terms_days'] || strcasecmp( $series->contact_email, $b->email ) !== 0 ) return null;
        }
        return $agreement;
    }
    public static function approval_guard( $b ) {
        if ( self::for_booking( $b ) && ! self::context( $b, false, true ) ) return new WP_Error( 'credit_review_required', 'This invoice account is disabled, suspended, expired or no longer matches the contact/date. Review the credit assignment before approval.' );
        return true;
    }
    /** Frozen issued due date, never current settings or a freshly edited agreement. */
    public static function invoice_info( $b ) {
        global $wpdb;
        if ( empty( $b->current_invoice_document_id ) ) return null;
        $json = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . " WHERE id=%d AND booking_id=%d AND document_type='invoice' AND status='issued'", $b->current_invoice_document_id, $b->id ) );
        $info = $json ? json_decode( $json, true ) : null;
        $date = is_array( $info ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $info['due_date'] ?? '' ), wp_timezone() ) : false;
        return $date && $date->format( 'Y-m-d' ) === $info['due_date'] ? $info : null;
    }
    public static function reminder_key( $b ) {
        return 'credit_reminder:' . hash( 'sha256', wp_json_encode( array( $b->ref, (int) $b->current_invoice_document_id, (int) $b->chase_count, $b->email, $b->amount, $b->amount_paid ) ) );
    }
    public static function queue_reminder( $ref ) {
        global $wpdb;
        $result = self::transaction( static function() use ( $ref, $wpdb ) {
            $b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . ' WHERE ref=%s FOR UPDATE', $ref ) );
            if ( ! $b || ! in_array( $b->status, array( 'confirmed','deposit_paid' ), true ) || (float) $b->amount <= (float) $b->amount_paid || ! self::for_booking( $b ) || ! MBS_Payment_Chaser::should_chase_occurrence( $b ) ) return new WP_Error( 'credit_reminder_ineligible', 'Invoice account is not currently due an individual reminder.' );
            $key = self::reminder_key( $b );
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . $wpdb->prefix . "mathlin_email_queue WHERE message_key=%s", $key ) );
            if ( $existing ) return $existing->status === 'failed' ? new WP_Error( 'credit_reminder_failed', 'A previous reminder attempt needs outbox review; it was not duplicated.' ) : true;
            $message = MBS_Email::build_credit_reminder( $b );
            if ( is_wp_error( $message ) ) return $message;
            $queued = MBS_Email_Queue::enqueue( $b->email, $message['subject'], $message['body'], $message['headers'], $key, hash( 'sha256', wp_json_encode( $message ) ), null, array( 'message_type' => 'credit_reminder', 'reference_type' => 'booking', 'reference_id' => (int) $b->id ) );
            if ( is_wp_error( $queued ) ) return $queued;
            if ( ! MBS_Audit_Log::log( $ref, 'credit_reminder_queued', 'Immutable-invoice account reminder queued idempotently; no cancellation action or delivery claim.' ) ) throw new RuntimeException( 'Could not audit the reminder intent.' );
            return true;
        } );
        if ( ! is_wp_error( $result ) ) MBS_Email_Queue::wake();
        else error_log( '[MGF Venue] Credit reminder not queued: ' . $result->get_error_code() . ' ' . $result->get_error_message() );
        return ! is_wp_error( $result );
    }
    public static function reminder_current( $email ) {
        $b = MBS_Bookings::get_by_id( (int) $email->reference_id );
        return $b && in_array( $b->status, array( 'confirmed','deposit_paid' ), true ) && (float) $b->amount > (float) $b->amount_paid
            && strcasecmp( $email->to_email, $b->email ) === 0 && self::for_booking( $b ) && MBS_Payment_Chaser::should_chase_occurrence( $b ) && $email->message_key === self::reminder_key( $b );
    }
    public static function reminder_delivered( $email ) {
        global $wpdb;
        $b = MBS_Bookings::get_by_id( (int) $email->reference_id );
        if ( ! $b || $email->message_key !== self::reminder_key( $b ) ) return;
        $saved = $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'chase_count' => (int) $b->chase_count + 1, 'last_chased' => current_time( 'mysql' ) ), array( 'id' => $b->id, 'email' => $b->email, 'status' => $b->status, 'amount' => $b->amount, 'amount_paid' => $b->amount_paid, 'chase_count' => $b->chase_count ) );
        MBS_Audit_Log::log( $b->ref, 'payment_chase', 'Invoice-account reminder accepted by transport, not proof of inbox delivery. Chase tracking ' . ( $saved === 1 ? 'updated.' : 'requires review; do not resend blindly.' ) );
    }
    public static function fingerprint( $target, $kind ) {
        $keys = $kind === 'series' ? array( 'series_ref','version','status','contact_email','start_date','repeat_until','billing_mode','billing_treatment','payment_terms_days' ) : array( 'ref','status','email','booking_date','booking_date_end','start_time','end_time','space','amount','amount_paid','current_invoice_document_id','series_id' );
        $values = array(); foreach ( $keys as $key ) $values[$key] = $target->$key ?? null;
        return hash( 'sha256', wp_json_encode( $values ) );
    }
    public static function url( $kind = '', $ref = '' ) {
        return admin_url( 'admin.php?page=mathlin-credit-accounts' . ( $kind ? '&scope_kind=' . rawurlencode( $kind ) . '&scope_ref=' . rawurlencode( $ref ) : '' ) );
    }
    private static function transaction( $callback ) {
        return MBS_Space_Selection::locked( static function() use ( $callback ) {
            global $wpdb;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'credit_transaction', 'Could not start the credit decision.' );
            try {
                $result = $callback();
                if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); return $result; }
                if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit the credit decision.' );
                return $result;
            } catch ( Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'credit_save_failed', $e->getMessage() );
            }
        } );
    }
    /** Public method still enforces staff authority; no customer endpoint can grant credit. */
    public static function decide( $input ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) || ! self::supported() ) return new WP_Error( 'credit_forbidden', 'Invoice accounts require a paid venue administrator.' );
        $op = $input['op'] ?? '';
        if ( ! self::enabled() ) return new WP_Error( 'credit_disabled', 'Enable invoice accounts before making a credit decision.' );
        return self::transaction( static function() use ( $input, $op, $wpdb ) {
            $now = current_time( 'mysql' );
            if ( $op === 'create' ) {
                $agreement = self::validate_agreement( $input );
                if ( is_wp_error( $agreement ) ) return $agreement;
                if ( empty( $input['verified'] ) ) return new WP_Error( 'credit_verification', 'Confirm that the organisation and authority to offer credit were verified.' );
                if ( $wpdb->insert( self::table(), array( 'agreement_json' => wp_json_encode( $agreement ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) ) !== 1 ) throw new RuntimeException( 'Could not create the agreement.' );
                $id = $wpdb->insert_id;
                if ( ! MBS_Audit_Log::log( 'CREDIT-' . $id, 'credit_account_created', 'Explicit invoice agreement created; terms ' . $agreement['terms_days'] . ' days; valid through ' . $agreement['valid_until'] . '; unpaid access ' . ( $agreement['allow_unpaid_access'] ? 'authorised' : 'not authorised' ) . '.' ) ) throw new RuntimeException( 'Could not audit the agreement.' );
                return true;
            }
            if ( $op === 'status' ) {
                $account = self::account( (int) ( $input['account_id'] ?? 0 ), true );
                $status = $input['status'] ?? '';
                if ( ! $account || (int) $account->version !== (int) ( $input['account_version'] ?? 0 ) || ! in_array( $status, array( 'active', 'suspended' ), true ) ) return new WP_Error( 'credit_stale', 'Agreement changed; refresh before deciding.' );
                $reason = sanitize_textarea_field( $input['reason'] ?? '' );
                if ( trim( $reason ) === '' ) return new WP_Error( 'credit_reason', 'Record the reason for this status decision.' );
                if ( $wpdb->update( self::table(), array( 'status' => $status, 'version' => (int) $account->version + 1, 'updated_at' => $now ), array( 'id' => $account->id, 'version' => $account->version ) ) !== 1 || ! MBS_Audit_Log::log( 'CREDIT-' . $account->id, 'credit_account_status', $status . ': ' . $reason ) ) throw new RuntimeException( 'Could not record the status decision.' );
                return true;
            }
            if ( $op !== 'assign' || empty( $input['verified'] ) ) return new WP_Error( 'credit_verification', 'Confirm the exact contact, scope, issued invoices and credit agreement.' );
            $kind = $input['scope_kind'] ?? ''; $ref = sanitize_text_field( $input['scope_ref'] ?? '' );
            if ( ! in_array( $kind, array( 'booking', 'series' ), true ) ) return new WP_Error( 'credit_scope', 'Invalid assignment scope.' );
            $table = $wpdb->prefix . ( $kind === 'series' ? MBS_SERIES_TABLE : MBS_TABLE );
            $key = $kind === 'series' ? 'series_ref' : 'ref';
            $target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$key}=%s FOR UPDATE", $ref ) );
            if ( ! $target || ! hash_equals( self::fingerprint( $target, $kind ), (string) ( $input['fingerprint'] ?? '' ) ) ) return new WP_Error( 'credit_stale_target', 'Booking or billing details changed; refresh and review again.' );
            if ( ! in_array( $target->status, array( 'pending','confirmed','deposit_paid','paid' ), true ) || ! empty( $target->scout_use ) || ( $kind === 'booking' && ( ! empty( $target->series_id ) || (float) $target->amount <= 0 ) ) ) return new WP_Error( 'credit_target', 'Only an active chargeable one-off or external series can receive credit. Review series at series level.' );
            $account = self::account( (int) ( $input['account_id'] ?? 0 ), true );
            if ( ! $account || $account->status !== 'active' || (int) $account->version !== (int) ( $input['account_version'] ?? 0 ) ) return new WP_Error( 'credit_stale', 'Agreement changed or is suspended; refresh before deciding.' );
            $email = $kind === 'series' ? $target->contact_email : $target->email;
            $date = $kind === 'series' ? ( $target->repeat_until ?: $target->start_date ) : ( $target->booking_date_end ?: $target->booking_date );
            $agreement = json_decode( $account->agreement_json, true );
            if ( ! self::permits( $agreement, (object) array( 'contact_email' => $email ), $email, $date, wp_date( 'Y-m-d' ) ) ) return new WP_Error( 'credit_identity', 'This contact/date is not covered by the agreement.' );
            if ( $kind === 'series' && ( $target->billing_treatment !== 'invoice_managed' || (int) $target->payment_terms_days !== $agreement['terms_days'] ) ) return new WP_Error( 'credit_series_terms', 'Review the invoice-managed series billing schedule first; its invoice days must match this agreement. No existing schedule is rewritten.' );
            $existing = self::assignment( $kind, $ref );
            if ( (int) ( $existing->version ?? 0 ) !== (int) ( $input['assignment_version'] ?? 0 ) ) return new WP_Error( 'credit_stale_assignment', 'The credit assignment changed; refresh before deciding.' );
            $reason = sanitize_textarea_field( $input['reason'] ?? '' );
            if ( trim( $reason ) === '' ) return new WP_Error( 'credit_reason', 'Record the reason and evidence for this credit decision.' );
            if ( $kind === 'booking' ) {
                $policy = MBS_Payment_Expiry::get( $ref );
                if ( $wpdb->last_error ) throw new RuntimeException( 'Could not verify the existing payment policy.' );
                if ( $policy ) {
                    $p = json_decode( $policy->policy_json, true );
                    if ( $policy->state !== 'active' || ! MBS_Payment_Expiry_Policy::valid( $p ) ) return new WP_Error( 'credit_funds_review', 'Resolve the existing cancellation/manual/funds review before changing credit treatment.' );
                    $p['exempt'] = true;
                    if ( $wpdb->update( MBS_Payment_Expiry::table(), array( 'policy_json' => wp_json_encode( $p ), 'version' => (int) $policy->version + 1, 'updated_at' => $now ), array( 'id' => $policy->id, 'version' => $policy->version ) ) !== 1 ) throw new RuntimeException( 'Payment policy changed during credit review.' );
                }
            }
            $data = array( 'account_id' => $account->id, 'contact_email' => strtolower( $email ), 'reviewed_by' => get_current_user_id(), 'reason' => $reason, 'updated_at' => $now );
            if ( $existing ) {
                $data['version'] = (int) $existing->version + 1;
                $saved = $wpdb->update( self::table( true ), $data, array( 'id' => $existing->id, 'version' => $existing->version ) );
            } else {
                $saved = $wpdb->insert( self::table( true ), array_merge( $data, array( 'scope_kind' => $kind, 'scope_ref' => $ref, 'created_at' => $now ) ) );
            }
            if ( $saved !== 1 || ! MBS_Audit_Log::log( $ref, 'credit_assigned', 'Explicit ' . $kind . ' assignment to CREDIT-' . $account->id . '; previous account ' . ( $existing->account_id ?? 'none' ) . '. No payment, issued invoice or approval changed. ' . $reason ) ) throw new RuntimeException( 'Could not safely record the credit assignment.' );
            return true;
        } );
    }

    public static function render_booking( $b ) {
        if ( ! self::supported() || ! current_user_can( 'manage_options' ) ) return;
        $link = self::for_booking( $b );
        $fields = json_decode( (string) ( $b->custom_fields ?? '' ), true );
        if ( ! self::enabled() && ! $link ) return;
        echo '<details class="nms-card mbs-disclosure"><summary class="nms-card-header"><h2>Invoice account / credit review</h2></summary><div class="mbs-card-body">';
        if ( ! empty( $fields['_mbs_credit_requested'] ) ) echo '<p><strong>Hirer requested invoice terms. This is not approval for credit.</strong></p>';
        echo '<p>' . esc_html( $link ? ( self::context( $b ) ? 'Explicit invoice account assigned. Issued invoices remain unchanged.' : 'Credit assignment needs review: disabled, expired, suspended or contact/date changed. No unpaid access is authorised.' ) : 'Ordinary payment rules apply. A login, organisation name or BACS payment does not grant credit.' ) . '</p><a class="button" href="' . esc_url( self::url( empty( $b->series_id ) ? 'booking' : 'series', empty( $b->series_id ) ? $b->ref : $b->series_id ) ) . '">Review invoice account</a></div></details>';
    }
    public static function attention( $limit ) {
        global $wpdb;
        if ( ! self::supported() ) return array();
        $items = array();
        $last_id = 0;
        for ( $page = 0; $page < 20; $page++ ) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT b.* FROM ' . $wpdb->prefix . MBS_TABLE . ' b JOIN ' . self::table( true ) . " c ON (c.scope_kind='booking' AND c.scope_ref=b.ref AND COALESCE(b.series_id,'')='') OR (c.scope_kind='series' AND c.scope_ref=b.series_id) WHERE b.id>%d AND b.status IN ('pending','confirmed','deposit_paid') AND b.amount>COALESCE(b.amount_paid,0) ORDER BY b.id LIMIT 100", $last_id ) );
            if ( ! is_array( $rows ) || $wpdb->last_error ) { $items[] = self::attention_failure( 'Credit assignments could not be verified' ); return $items; }
            foreach ( $rows as $b ) {
                $last_id = (int) $b->id;
                $credit = self::context( $b );
                $invoice = empty( $b->series_id ) ? self::invoice_info( $b ) : null;
                $finding = ! $credit ? 'Assigned credit requires manual review; unpaid access is not authorised' : '';
                if ( empty( $b->series_id ) && $b->status !== 'pending' && ( ! $invoice || $invoice['due_date'] < wp_date( 'Y-m-d' ) ) ) $finding = $invoice ? 'Invoice account balance overdue under its issued due date; staff decision required, no automatic cancellation' : 'Credit hire has no verifiable issued invoice; review before chasing';
                if ( $credit && empty( $credit['allow_unpaid_access'] ) && $b->booking_date >= wp_date( 'Y-m-d' ) && $b->booking_date <= wp_date( 'Y-m-d', strtotime( '+1 day' ) ) ) $finding = 'Upcoming account hire is unpaid and access before payment was not authorised';
                if ( $finding === '' ) continue;
                $items[] = array( 'kind' => 'credit', 'ref' => $b->ref, 'since' => $invoice['due_date'] ?? null, 'finding' => $finding, 'action' => 'Review agreement, identity and payment; do not automatically cancel or rearm old deadlines', 'url' => self::url( empty( $b->series_id ) ? 'booking' : 'series', empty( $b->series_id ) ? $b->ref : $b->series_id ) );
                if ( count( $items ) >= $limit ) return $items;
            }
            if ( count( $rows ) < 100 ) return $items;
        }
        $items[] = self::attention_failure( 'Credit scan reached its 2,000-record safety limit; remaining assignments need review' );
        return $items;
    }
    private static function attention_failure( $finding ) {
        return array( 'kind' => 'credit', 'ref' => 'Invoice accounts', 'since' => null, 'finding' => $finding, 'action' => 'Inspect agreement and database state; this overview is incomplete', 'url' => self::url() );
    }
    private static function form_start( $op ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mbs_credit_accounts"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
        wp_nonce_field( 'mbs_credit_accounts' );
    }
    public static function page() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) || ! self::supported() ) wp_die( 'Invoice accounts are available only to paid venue administrators.' );
        echo '<div class="wrap mbs-admin mbs-credit-page"><h1>Approved invoice accounts</h1><p>Credit is separate from pricing, website logins and payment method. No booking is approved, payment recorded or email sent here.</p><section class="nms-card"><div class="mbs-card-body">';
        self::form_start( 'enable' );
        echo '<label><input type="checkbox" name="enabled" value="1" ' . checked( self::enabled(), true, false ) . '> Enable explicitly approved invoice accounts</label><p>Disabling stops new grants and unpaid access. Existing cancellation exemptions are retained for manual review.</p><button class="button">Save feature preference</button></form></div></section>';
        if ( ! self::enabled() ) { echo '</div>'; return; }
        $accounts = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC' );
        if ( ! is_array( $accounts ) || $wpdb->last_error ) { echo '<p role="alert">Could not read invoice agreements.</p></div>'; return; }
        $kind = sanitize_key( $_GET['scope_kind'] ?? '' ); $ref = sanitize_text_field( wp_unslash( $_GET['scope_ref'] ?? '' ) );
        $target = $kind === 'booking' ? MBS_Bookings::get( $ref ) : ( $kind === 'series' ? MBS_Series::get( $ref ) : null );
        if ( $target ) {
            $existing = self::assignment( $kind, $ref );
            echo '<section class="nms-card"><div class="mbs-card-body"><h2>Assign credit: ' . esc_html( $ref ) . '</h2><p>Contact: ' . esc_html( $kind === 'series' ? $target->contact_email : $target->email ) . '. Existing invoices and billing recipients remain unchanged. Series use their reviewed invoice schedule. No blanket permission for other bookings is granted.</p>';
            self::form_start( 'assign' );
            foreach ( array( 'scope_kind' => $kind, 'scope_ref' => $ref, 'fingerprint' => self::fingerprint( $target, $kind ), 'assignment_version' => $existing->version ?? 0 ) as $name => $value ) echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
            echo '<label for="credit-account">Verified agreement</label> <select id="credit-account" name="account_choice" required><option value="">Select an agreement</option>';
            foreach ( $accounts as $a ) { $g = json_decode( $a->agreement_json, true ); if ( $a->status === 'active' ) echo '<option value="' . (int) $a->id . ':' . (int) $a->version . '">CREDIT-' . (int) $a->id . ' — ' . esc_html( $g['name'] . ' / ' . $g['terms_days'] . ' days / until ' . $g['valid_until'] . ' / unpaid access ' . ( $g['allow_unpaid_access'] ? 'allowed' : 'not allowed' ) ) . '</option>'; }
            echo '</select><p><label for="credit-reason">Decision reason / verification evidence</label><br><textarea id="credit-reason" name="reason" required rows="3" class="large-text"></textarea></p><p><label><input type="checkbox" name="verified" value="1" required> I verified this contact is authorised, the scope and agreement, all issued invoices and any cancellation policy. I approve exemption from automatic cancellation for this scope.</label></p><button class="button button-primary">Save explicit credit assignment</button></form></div></section>';
        }
        echo '<section class="nms-card"><div class="mbs-card-body"><h2>Existing agreements</h2><p>Terms and contacts are immutable. Create a new agreement to change them, then explicitly reassign affected scopes. Suspension holds unpaid access, not already-delivered codes, and never automatically cancels hire.</p>';
        foreach ( $accounts as $a ) {
            $g = json_decode( $a->agreement_json, true );
            echo '<details class="mbs-disclosure"><summary>CREDIT-' . (int) $a->id . ' — ' . esc_html( $g['name'] . ' / ' . $a->status ) . '</summary><p>' . esc_html( implode( ', ', $g['contacts'] ) . ' | ' . $g['terms_days'] . ' invoice days | until ' . $g['valid_until'] . ' | unpaid access ' . ( $g['allow_unpaid_access'] ? 'allowed' : 'not allowed' ) ) . '</p>';
            self::form_start( 'status' );
            echo '<input type="hidden" name="account_id" value="' . (int) $a->id . '"><input type="hidden" name="account_version" value="' . (int) $a->version . '"><input type="hidden" name="status" value="' . ( $a->status === 'active' ? 'suspended' : 'active' ) . '"><label>Reason <input type="text" name="reason" required></label> <button class="button">' . ( $a->status === 'active' ? 'Suspend credit' : 'Reinstate agreement' ) . '</button></form></details>';
        }
        echo '</div></section><section class="nms-card"><div class="mbs-card-body"><h2>Create a verified agreement</h2>';
        self::form_start( 'create' );
        echo '<p><label for="credit-name">Organisation</label><br><input id="credit-name" name="name" required maxlength="200" class="regular-text"></p><p><label for="credit-contacts">Authorised contact emails (comma separated, maximum 30)</label><br><textarea id="credit-contacts" name="contacts" required rows="3" class="large-text"></textarea></p><p><label for="credit-days">Invoice terms (days, 1–90)</label> <input id="credit-days" type="number" min="1" max="90" name="terms_days" required></p><p><label for="credit-until">Agreement valid through</label> <input id="credit-until" type="date" name="valid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"></p><p><label><input type="checkbox" name="allow_unpaid_access" value="1"> Explicitly authorise access before invoice payment (otherwise full payment is still required for access)</label></p><p><label><input type="checkbox" name="verified" value="1" required> I verified this organisation, authorised contacts and agreement to offer credit.</label></p><button class="button button-primary">Create agreement (does not enrol bookings)</button></form></div></section></div>';
    }
    public static function admin_action() {
        if ( ! current_user_can( 'manage_options' ) || ! self::supported() ) wp_die( 'Not authorised.', '', array( 'response' => 403 ) );
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) wp_die( 'POST required.', '', array( 'response' => 405 ) );
        check_admin_referer( 'mbs_credit_accounts' );
        $input = wp_unslash( $_POST );
        if ( ( $input['op'] ?? '' ) === 'enable' ) {
            $enabled = ! empty( $input['enabled'] );
            // Option is not transactional; audit first and fail closed on error.
            if ( ! MBS_Audit_Log::log( 'CREDIT', 'credit_feature_preference', $enabled ? 'Invoice accounts enabled; no accounts or bookings automatically enrolled.' : 'Invoice accounts disabled; retained exemptions require manual review.' ) ) wp_die( 'Could not audit preference.' );
            update_option( 'mbs_credit_accounts_enabled', $enabled, false );
            $result = true;
        } else {
            if ( isset( $input['account_choice'] ) && preg_match( '/^(\d+):(\d+)$/', $input['account_choice'], $m ) ) { $input['account_id'] = $m[1]; $input['account_version'] = $m[2]; }
            $result = self::decide( $input );
        }
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'Credit decision not saved', array( 'back_link' => true, 'response' => 409 ) );
        wp_safe_redirect( self::url( sanitize_key( $input['scope_kind'] ?? '' ), sanitize_text_field( $input['scope_ref'] ?? '' ) ) );
        exit;
    }
}
