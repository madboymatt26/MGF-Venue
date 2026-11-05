<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicitly opted-in one-off hire expiry. No legacy-booking backfill. */
class MBS_Payment_Expiry {
    const HOOK = 'mbs_payment_expiry_tick';
    public static function table() { global $wpdb; return $wpdb->prefix . 'mathlin_payment_expiry'; }
    public static function schema() {
        global $wpdb; $table = self::table(); $charset = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref VARCHAR(20) NOT NULL,
            version BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            state VARCHAR(30) NOT NULL DEFAULT 'active',
            policy_json LONGTEXT NOT NULL,
            review_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
            KEY state (state)
        ) {$charset};";
    }
    public static function enabled() {
        return (bool) get_option( 'mbs_payment_expiry_enabled', false ) && MBS_Payment_Features::enabled( 'payments' ) && ! MBS_Campsite_Policy::enabled();
    }
    public static function eligible( $b ) {
        return empty( $b->scout_use ) && empty( $b->series_id ) && ! MBS_Access_Details::is_estate_booking( $b ) && ! MBS_Bookings::booking_is_offline( $b ) && (float) $b->amount > 0;
    }
    public static function get( $ref ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_ref=%s', $ref ) );
    }
    public static function init() {
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
        add_filter( 'cron_schedules', static function( $s ) { $s['mbs_expiry_five_minutes'] = array( 'interval' => 300, 'display' => 'MGF payment deadline checks' ); return $s; } );
        if ( self::enabled() && ! wp_next_scheduled( self::HOOK ) ) wp_schedule_event( time() + 60, 'mbs_expiry_five_minutes', self::HOOK );
        add_action( 'admin_post_mbs_payment_expiry', array( __CLASS__, 'admin_action' ) );
        add_filter( 'woocommerce_order_needs_payment', static function( $needs, $order ) {
            $ref = $order->get_meta( '_mbs_booking_ref' );
            return $ref && self::get( $ref ) && ! self::checkout_allowed( $ref ) ? false : $needs;
        }, 10, 2 );
        add_action( 'admin_menu', static function() { add_submenu_page( null, 'Payment policy and reviews', 'Payment policy', 'manage_options', 'mathlin-payment-expiry', array( __CLASS__, 'settings_page' ) ); } );
    }
    /** Called inside the approval transaction, before the invoice is frozen. */
    public static function enroll( $b, $notify_hirer, $ack = false, $manual_reason = '' ) {
        global $wpdb;
        if ( ! self::enabled() || ! self::eligible( $b ) ) return true;
        if ( self::get( $b->ref ) ) return new WP_Error( 'expiry_existing_policy', 'This booking already has a payment policy; review it rather than resetting its deadlines.' );
        if ( ! $notify_hirer ) return new WP_Error( 'expiry_notification_required', 'Policy-controlled approval must send the approval email containing the payment deadlines.' );
        $total = MBS_Money::from_decimal_string( (string) $b->amount );
        $settings = MBS_Bookings::get_deposit_settings();
        $deposit = $settings['enabled'] ? MBS_Money::from_decimal_string( number_format( MBS_Bookings::calculate_deposit( (float) $b->amount ), 2, '.', '' ) ) : 0;
        if ( is_wp_error( $total ) || is_wp_error( $deposit ) ) return new WP_Error( 'expiry_money_invalid', 'Cannot verify the approved hire amounts.' );
        $policy = MBS_Payment_Expiry_Policy::approve( time(), $b->booking_date, $total, $deposit, true, $ack );
        $state = 'active'; $review = array();
        if ( is_wp_error( $policy ) ) {
            if ( $policy->get_error_code() !== 'expiry_manual_approval_required' || ! current_user_can( 'manage_options' ) || trim( $manual_reason ) === '' ) return $policy;
            $state = 'manual'; $policy = array( 'manual_approval' => true, 'approved_at' => time(), 'reviewer' => get_current_user_id(), 'reason' => $manual_reason );
            $review = array( 'operational_issue' => 'Manual short-notice approval after cutoff: verify full payment before releasing access.' );
        }
        $ok = $wpdb->insert( self::table(), array( 'booking_ref' => $b->ref, 'state' => $state, 'policy_json' => wp_json_encode( $policy ), 'review_json' => wp_json_encode( $review ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
        if ( $ok !== 1 || ! MBS_Audit_Log::log( $b->ref, 'payment_policy_enrolled', $state === 'manual' ? 'Explicit administrator short-notice approval after cutoff: ' . $manual_reason : 'New approval enrolled in payment expiry policy v1; deadlines frozen at approval.' ) ) return new WP_Error( 'expiry_enroll_failed', 'Could not safely record the approval payment policy.' );
        return true;
    }
    public static function warning( $b ) {
        $row = self::get( $b->ref );
        if ( ! $row || $row->state !== 'active' || ! self::enabled() ) return '';
        $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid );
        return is_wp_error( $paid ) ? '' : MBS_Payment_Expiry_Policy::warning_text( json_decode( $row->policy_json, true ), $paid );
    }
    public static function approval_text( $b ) {
        $row = self::get( $b->ref );
        if ( ! $row ) return '';
        if ( $row->state === 'manual' ) return 'This hire has been approved after manual short-notice review. Full payment is required before access details can be issued. Please contact the booking coordinator if payment has not yet been arranged.';
        $p = json_decode( $row->policy_json, true );
        if ( ! MBS_Payment_Expiry_Policy::valid( $p ) || $p['exempt'] ) return '';
        $text = $p['deposit_due_at'] !== null ? 'If your deposit is not paid within 48 hours of approval, your booking will unfortunately be cancelled to make the venue available for others again. ' : '';
        $text .= self::warning( $b );
        $final = ( new DateTimeImmutable( '@' . $p['balance_cutoff_at'] ) )->setTimezone( new DateTimeZone( $p['timezone'] ) )->format( 'j F Y \a\t H:i T' );
        return $text . ' Any unpaid final balance will result in cancellation at ' . $final . ', when access details would otherwise become due.';
    }
    public static function checkout_allowed( $ref ) {
        $row = self::get( $ref );
        if ( ! $row ) return true;
        $b = MBS_Bookings::get( $ref );
        if ( ! $b || in_array( $b->status, array( 'cancelled', 'archived' ), true ) ) return false;
        if ( ! self::enabled() ) return true;
        if ( $row->state === 'manual' ) return true;
        $p = json_decode( $row->policy_json, true );
        $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid );
        if ( ! MBS_Payment_Expiry_Policy::valid( $p ) || is_wp_error( $paid ) || $row->state !== 'active' ) return false;
        return $p['exempt'] || time() < MBS_Payment_Expiry_Policy::next_deadline( $p, $paid )['at'];
    }
    public static function email_current( $email ) {
        global $wpdb;
        $b = MBS_Bookings::get_by_id( (int) $email->reference_id );
        $row = $b ? self::get( $b->ref ) : null;
        if ( ! $b || ! $row || ! empty( $wpdb->last_error ) ) return false;
        if ( $email->message_type === 'expiry_warning' ) {
            if ( strcasecmp( $email->to_email, $b->email ) !== 0 ) return false;
            if ( ! self::enabled() || $row->state !== 'active' || ! in_array( $b->status, array( 'confirmed', 'deposit_paid' ), true ) ) return false;
            $p = json_decode( $row->policy_json, true ); $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid );
            if ( ! MBS_Payment_Expiry_Policy::valid( $p ) || is_wp_error( $paid ) || $p['exempt'] || $paid >= $p['total_minor'] ) return false;
            $d = MBS_Payment_Expiry_Policy::next_deadline( $p, $paid );
            return time() < $d['at'] && $email->message_key === 'expiry_warning:' . $b->ref . ':' . $d['at'] . ':' . $paid;
        }
        return $email->message_type !== 'expiry_cancel' || ( strcasecmp( $email->to_email, $b->email ) === 0 && in_array( $b->status, array( 'cancelled', 'archived' ), true ) );
    }
    private static function save( $row, $state, $policy, $review ) {
        global $wpdb;
        return $wpdb->update( self::table(), array( 'state' => $state, 'policy_json' => wp_json_encode( $policy ), 'review_json' => wp_json_encode( $review ), 'version' => (int) $row->version + 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $row->id, 'version' => $row->version ) ) === 1;
    }
    private static function queue( $b, $type, $text, $key, $admin = false ) {
        $to = $admin ? MBS_Bookings::get_admin_email() : $b->email;
        $subject = ( $type === 'cancel' ? 'Booking cancelled due to non-payment — ' : ( $admin ? 'Booking payment review required — ' : 'Payment deadline — ' ) ) . $b->ref;
        $org = MBS_Email_Templates::get_org_settings();
        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:auto"><h2 style="color:#7413DC">' . esc_html( $org['name'] ) . '</h2><p>' . nl2br( esc_html( $text ) ) . '</p><p>Kind regards,<br>' . esc_html( $org['name'] ) . '</p></body></html>';
        $headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . MBS_Bookings::get_admin_email() );
        return MBS_Email_Queue::enqueue( $to, $subject, $body, $headers, $key, MBS_Email_Queue::compute_payload_hash( $to, $subject, $body, $headers ), null, array( 'message_type' => 'expiry_' . $type, 'reference_type' => 'booking', 'reference_id' => (int) $b->id ) );
    }
    /** A returned WP_Error or ambiguous order always blocks cancellation. */
    public static function orders( $ref ) {
        global $wpdb;
        if ( ! function_exists( 'wc_get_order' ) ) return new WP_Error( 'expiry_checkout_unavailable', 'Cannot verify checkout state.' );
        try {
            // Line-item association is authoritative and also covers checkout
            // started before approval. Woo uses these item tables in CPT/HPOS;
            // load each order through its native data store, never postmeta.
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.order_id FROM {$wpdb->prefix}woocommerce_order_items i INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id=i.order_item_id WHERE m.meta_key='_mbs_booking_ref' AND m.meta_value=%s ORDER BY i.order_id LIMIT 1001", $ref ) );
            if ( ! is_array( $ids ) || $wpdb->last_error || count( $ids ) > 1000 ) return new WP_Error( 'expiry_orders_unknown', 'Cannot completely verify checkout orders.' );
            $orders = array();
            foreach ( $ids as $id ) { $order = wc_get_order( (int) $id ); if ( ! $order ) return new WP_Error( 'expiry_order_missing', 'Associated payment order cannot be verified.' ); $orders[] = $order; }
            return $orders;
        } catch ( Throwable $e ) { return new WP_Error( 'expiry_orders_unknown', 'Checkout state could not be verified.' ); }
    }
    public static function payment_clear( $b, $orders ) {
        if ( is_wp_error( $orders ) ) return false;
        $captured = 0;
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) if ( $item->get_meta( '_mbs_booking_ref' ) !== $b->ref ) return false;
            if ( $order->get_status() === 'on-hold' ) return false;
            if ( $order->get_status() === 'pending' && $order->get_date_modified() && time() - $order->get_date_modified()->getTimestamp() < 900 ) return false;
            if ( $order->is_paid() && $order->get_meta( '_mbs_payment_processed' ) !== 'yes' ) return false;
            if ( $order->is_paid() ) {
                $amount = MBS_Money::from_decimal_string( (string) $order->get_total() );
                if ( is_wp_error( $amount ) || $order->get_currency() !== 'GBP' ) return false;
                $captured += $amount;
            }
        }
        $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid );
        return ! is_wp_error( $paid ) && $captured <= $paid;
    }
    public static function tick() {
        if ( ! self::enabled() ) return;
        global $wpdb; $after = 0;
        do {
            $page = $wpdb->get_results( $wpdb->prepare( 'SELECT id,booking_ref FROM ' . self::table() . " WHERE state='active' AND id>%d ORDER BY id LIMIT 100", $after ) );
            if ( ! is_array( $page ) ) return;
            foreach ( $page as $row ) {
                $after = (int) $row->id; $result = self::check( $row->booking_ref );
                if ( is_wp_error( $result ) ) {
                    $fresh = self::get( $row->booking_ref );
                    if ( $fresh && $fresh->state === 'active' ) {
                        $review = json_decode( $fresh->review_json, true ) ?: array();
                        $review['operational_issue'] = 'Deadline check failed safely: ' . $result->get_error_message();
                        self::save( $fresh, 'active', json_decode( $fresh->policy_json, true ), $review );
                    }
                }
            }
        } while ( count( $page ) === 100 );
        MBS_Email_Queue::wake();
    }
    public static function check( $ref, $late_order_id = 0, $now = null ) {
        $now = $now === null ? time() : (int) $now;
        return MBS_Space_Selection::locked( static function() use ( $ref, $late_order_id, $now ) {
            global $wpdb; $table = $wpdb->prefix . MBS_TABLE;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'expiry_transaction', 'Could not start deadline check.' );
            try {
                $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref=%s FOR UPDATE", $ref ) );
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_ref=%s FOR UPDATE', $ref ) );
                if ( ! self::enabled() || ! $b || ! $row || $row->state !== 'active' ) { $wpdb->query( 'ROLLBACK' ); return true; }
                $p = json_decode( $row->policy_json, true ); $review = json_decode( $row->review_json, true ) ?: array();
                $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid ); $total = MBS_Money::from_decimal_string( (string) $b->amount );
                $orders = self::orders( $ref );
                if ( $late_order_id && is_array( $orders ) ) $orders = array_filter( $orders, static function( $o ) use ( $late_order_id ) { return (int) $o->get_id() !== (int) $late_order_id; } );
                $decision = MBS_Payment_Expiry_Policy::decision( $p, $now, $paid, $b->status, self::payment_clear( $b, $orders ), $total, $b->booking_date );
                if ( ! self::eligible( $b ) ) $decision = array( 'action' => 'review', 'reason' => 'billing_treatment_changed' );
                $starts = new DateTimeImmutable( $b->booking_date . ' ' . ( $b->start_time ?: '00:00:00' ), wp_timezone() );
                if ( $decision['action'] === 'cancel' && $now >= $starts->getTimestamp() ) $decision = array( 'action' => 'review', 'reason' => 'missed_deadline_hire_started' );
                if ( $decision['action'] === 'review' ) {
                    // Keep active for safe rechecks; expose ambiguity persistently.
                    $review['operational_issue'] = $decision['reason'];
                    if ( ! self::save( $row, 'active', $p, $review ) ) throw new RuntimeException( 'Could not persist payment review.' );
                } elseif ( $decision['action'] === 'warn' ) {
                    $key = 'expiry_warning:' . $ref . ':' . $decision['deadline'] . ':' . $paid;
                    $text = 'Hi ' . $b->name . ",\n\n" . MBS_Payment_Expiry_Policy::warning_text( $p, $paid ) . "\n\nBooking: " . $ref . '. Please use your booking account to pay, or contact us if you have already paid.';
                    $result = self::queue( $b, 'warning', $text, $key );
                    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
                } elseif ( $decision['action'] === 'cancel' ) {
                    $review = array( 'received_minor' => $paid, 'retained_minor' => 0, 'bank_refunds' => array(), 'reason' => $decision['reason'], 'cancelled_at' => time(), 'operational_issue' => '', 'decisions' => array() );
                    if ( $wpdb->update( $table, array( 'status' => 'cancelled', 'access_sent' => 0 ), array( 'ref' => $ref, 'status' => $b->status, 'amount_paid' => $b->amount_paid ) ) !== 1 ) throw new RuntimeException( 'Booking changed before cancellation.' );
                    if ( ! self::save( $row, $paid > 0 ? 'money_review' : 'cancelled', $p, $review ) ) throw new RuntimeException( 'Could not persist cancellation review.' );
                    $fresh = MBS_Bookings::get( $ref );
                    $ha = MBS_HomeAssistant::stage_within_transaction( $fresh, $b->status );
                    if ( is_wp_error( $ha ) || ! MBS_Audit_Log::log( $ref, 'unpaid_booking_cancelled', 'Automatically cancelled under the approved payment deadline policy: ' . $decision['reason'] . '. Receipts preserved; no refund performed.' ) ) throw new RuntimeException( 'Could not persist cancellation integration/audit.' );
                    $text = 'Hi ' . $b->name . ",\n\nYour booking " . $ref . ' on ' . $b->booking_date . ' has unfortunately been cancelled because the required ' . ( $decision['reason'] === 'unpaid_deposit' ? 'deposit' : 'balance' ) . " was not received by the payment deadline. The venue is now available for others to book and no access code will be issued.\n\n";
                    if ( $paid > 0 ) $text .= 'We have recorded £' . number_format( $paid / 100, 2 ) . " already paid. This is awaiting manual review; this email does not confirm a refund or retention decision.\n\n";
                    $text .= 'Please contact us if you believe payment has already been made.';
                    foreach ( array( self::queue( $b, 'cancel', $text, 'expiry_cancel:' . $ref ), self::queue( $b, 'admin', 'Booking ' . $ref . ' was cancelled due to non-payment. ' . ( $paid > 0 ? 'Money is held and requires a refund/retention review. ' : '' ) . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $ref ) ), 'expiry_admin:' . $ref, true ) ) as $queued ) if ( is_wp_error( $queued ) ) throw new RuntimeException( 'Could not queue cancellation notifications.' );
                }
                if ( in_array( $decision['action'], array( 'none', 'warn', 'wait' ), true ) && ! empty( $review['operational_issue'] ) ) {
                    unset( $review['operational_issue'] );
                    if ( ! self::save( $row, 'active', $p, $review ) ) throw new RuntimeException( 'Could not clear a resolved operational review.' );
                }
                if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit deadline check.' );
                return true;
            } catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'expiry_check_failed', $e->getMessage() ); }
        } );
    }
    /** Admin review balance: native order refunds are read, never requested here. */
    public static function review_balance( $row, $ignore_retention = false ) {
        $review = json_decode( $row->review_json, true );
        if ( ! is_array( $review ) || ! isset( $review['received_minor'] ) ) return new WP_Error( 'review_missing', 'No money-held cancellation review exists.' );
        $orders = self::orders( $row->booking_ref );
        if ( is_wp_error( $orders ) ) return $orders;
        $refunds = 0;
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) if ( $item->get_meta( '_mbs_booking_ref' ) !== $row->booking_ref ) return new WP_Error( 'review_shared_order', 'A shared order needs allocation review before resolving refunds.' );
            $minor = MBS_Money::from_decimal_string( (string) $order->get_total_refunded() );
            if ( is_wp_error( $minor ) ) return $minor;
            $refunds += $minor;
        }
        foreach ( $review['bank_refunds'] ?? array() as $refund ) $refunds += (int) $refund['amount_minor'];
        return MBS_Money_Held_Review::balance( (int) $review['received_minor'], $refunds, $ignore_retention ? 0 : (int) ( $review['retained_minor'] ?? 0 ) );
    }
    /** Captured money after cancellation creates review; never restores occupancy. */
    public static function late_payment( $ref, $order ) {
        global $wpdb; $row = self::get( $ref );
        if ( ! $row || ! in_array( $row->state, array( 'active', 'cancelled', 'money_review', 'resolved' ), true ) ) return;
        $result = MBS_Space_Selection::locked( static function() use ( $ref, $order ) {
            global $wpdb;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return false;
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_ref=%s FOR UPDATE', $ref ) );
            $review = $row ? json_decode( $row->review_json, true ) : null;
            $minor = MBS_Money::from_decimal_string( (string) $order->get_total() );
            if ( ! $row || ! is_array( $review ) || is_wp_error( $minor ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            if ( in_array( (int) $order->get_id(), $review['late_orders'] ?? array(), true ) ) { $wpdb->query( 'ROLLBACK' ); return true; }
            $b = MBS_Bookings::get( $ref );
            $prior_paid = $b ? MBS_Money::from_decimal_string( (string) $b->amount_paid ) : new WP_Error( 'missing_booking', 'Booking missing.' );
            if ( is_wp_error( $prior_paid ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            $review['received_minor'] = (int) ( $review['received_minor'] ?? $prior_paid ) + $minor;
            if ( $row->state === 'active' ) $review['operational_issue'] = 'Late capture requires manual booking/payment decision; access not released.';
            $review['late_orders'][] = (int) $order->get_id();
            $b = MBS_Bookings::get( $ref );
            $mail = $b ? self::queue( $b, 'admin', 'A payment was received after its payment deadline for ' . $ref . '. Review the booking status and held payment/refund; do not reopen a released slot without checking availability. ' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $ref ) ), 'expiry_late:' . $ref . ':' . $order->get_id(), true ) : new WP_Error( 'missing_booking', 'Booking missing.' );
            if ( is_wp_error( $mail ) || ! self::save( $row, 'money_review', json_decode( $row->policy_json, true ), $review ) || ! MBS_Audit_Log::log( $ref, 'late_payment_review', 'Captured payment after cancellation: order ' . $order->get_id() . '. Booking remains cancelled.' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            return $wpdb->query( 'COMMIT' ) !== false;
        } );
        if ( is_wp_error( $result ) || ! $result ) throw new RuntimeException( 'Could not persist late-payment review; payment callback must be retried.' );
        MBS_Email_Queue::wake();
    }
    public static function needs_attention( $limit ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE state='money_review' OR (state IN ('active','manual') AND review_json LIKE %s) ORDER BY id LIMIT %d", '%"operational_issue":%', $limit ) );
        $items = array();
        foreach ( (array) $rows as $row ) {
            if ( $row->state === 'manual' ) { $booking = MBS_Bookings::get( $row->booking_ref ); if ( $booking && $booking->status === 'paid' ) continue; }
            $review = json_decode( $row->review_json, true );
            if ( $row->state !== 'money_review' && empty( $review['operational_issue'] ) ) continue;
            $items[] = array( 'kind' => 'payment_review', 'ref' => $row->booking_ref, 'since' => $row->updated_at, 'finding' => $row->state === 'money_review' ? 'Money held — booking/payment and refund/retention review required' : 'Payment deadline needs review: ' . $review['operational_issue'], 'action' => 'Review payment evidence and resolve explicitly', 'url' => admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $row->booking_ref ) ) );
            if ( count( $items ) >= $limit ) break;
        }
        return $items;
    }
    /** Refund webhooks never reopen an expired booking or discard receipts. */
    public static function refund_review( $ref, $order_id, $refund_id ) {
        return MBS_Space_Selection::locked( static function() use ( $ref, $order_id, $refund_id ) {
            global $wpdb;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'refund_review_start', 'Could not start refund review.' );
            try {
                $b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . ' WHERE ref=%s FOR UPDATE', $ref ) );
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_ref=%s FOR UPDATE', $ref ) );
                if ( ! $b || ! $row ) throw new RuntimeException( 'Refund review source missing.' );
                $r = json_decode( $row->review_json, true ) ?: array();
                $event = (int) $order_id . ':' . (int) $refund_id;
                if ( in_array( $event, $r['refund_events'] ?? array(), true ) ) { $wpdb->query( 'ROLLBACK' ); return true; }
                $paid = MBS_Money::from_decimal_string( (string) $b->amount_paid );
                if ( is_wp_error( $paid ) ) throw new RuntimeException( 'Cannot verify receipts.' );
                if ( ! isset( $r['received_minor'] ) ) $r['received_minor'] = $paid;
                $r['refund_events'][] = $event;
                $r['operational_issue'] = 'Refund recorded by WooCommerce; verify remaining funds and booking decision.';
                if ( ! self::save( $row, 'money_review', json_decode( $row->policy_json, true ), $r ) ) throw new RuntimeException( 'Could not save refund review.' );
                $update = array( 'access_sent' => 0 );
                if ( $b->status === 'paid' ) $update['status'] = 'confirmed';
                if ( $wpdb->update( $wpdb->prefix . MBS_TABLE, $update, array( 'ref' => $ref ) ) === false ) throw new RuntimeException( 'Could not freeze access pending refund review.' );
                if ( ! MBS_Audit_Log::log( $ref, 'refund_review_required', 'Native order refund ' . $event . ' requires review; no receipt history erased or cancelled slot reopened.' ) ) throw new RuntimeException( 'Could not record refund audit.' );
                $mail = self::queue( $b, 'admin', 'A native payment refund was recorded for ' . $ref . '. Verify refunds, retained funds and the booking status. ' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $ref ) ), 'expiry_refund:' . $ref . ':' . $event, true );
                if ( is_wp_error( $mail ) ) throw new RuntimeException( $mail->get_error_message() );
                if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit refund review.' );
                MBS_Email_Queue::wake();
                return true;
            } catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'refund_review_failed', $e->getMessage() ); }
        } );
    }
    private static function form( $row, $operation ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mbs_payment_expiry"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( 'mbs_payment_expiry' );
        if ( $row ) echo '<input type="hidden" name="ref" value="' . esc_attr( $row->booking_ref ) . '"><input type="hidden" name="version" value="' . (int) $row->version . '">';
    }
    public static function render_booking( $b ) {
        $row = self::get( $b->ref );
        if ( ! $row && ( ! self::enabled() || $b->status !== 'pending' || ! self::eligible( $b ) ) ) return;
        echo '<section class="nms-card"><div class="nms-card-header"><h2>Payment deadlines and money review</h2></div><div class="mbs-card-body">';
        if ( ! $row ) {
            $cutoff = ( new DateTimeImmutable( $b->booking_date, wp_timezone() ) )->modify( '-1 day' )->setTime( 8, 0 );
            echo '<p>Approval starts a 48-hour deposit deadline. Full payment is required by ' . esc_html( $cutoff->format( 'j F Y H:i T' ) ) . '. The approval email will warn of automatic cancellation.</p>';
            if ( $cutoff->getTimestamp() - time() < 172800 ) echo '<p role="alert">Short notice: full payment is required by that cutoff. Approval at/after cutoff requires manual review.</p><label><input type="checkbox" id="mbs-expiry-short-ack"> I acknowledge the shortened full-payment deadline.</label>';
            if ( $cutoff->getTimestamp() <= time() && current_user_can( 'manage_options' ) ) echo '<p><label>Manual approval after cutoff: record your review and payment arrangements <input id="mbs-expiry-manual-reason" required></label></p>';
        } else {
            $p = json_decode( $row->policy_json, true ); $review = json_decode( $row->review_json, true ) ?: array();
            echo '<p>Status: <strong>' . esc_html( $row->state ) . '</strong></p><p>' . esc_html( self::warning( $b ) ) . '</p>';
            if ( ! empty( $review['operational_issue'] ) ) echo '<p role="alert">Review required: ' . esc_html( $review['operational_issue'] ) . '</p>';
            if ( $row->state === 'active' && current_user_can( 'manage_options' ) ) {
                self::form( $row, 'extension' );
                echo '<p><label>Extend all payment cancellation deadlines until (Europe/London) <input type="datetime-local" name="until" required></label></p><p><label>Reason <input name="reason" required></label></p><button class="button">Grant recorded extension</button></form>';
                self::form( $row, 'exempt' ); echo '<p><label>Exemption reason <input name="reason" required></label> <button class="button">Exempt from automatic cancellation</button></p></form>';
            }
            if ( isset( $review['received_minor'] ) ) {
                $balance = self::review_balance( $row );
                if ( is_wp_error( $balance ) ) echo '<p role="alert">' . esc_html( $balance->get_error_message() ) . '</p>';
                else echo '<p><strong>Money-held review — booking status: ' . esc_html( $b->status ) . '</strong><br>Received: £' . esc_html( number_format( $balance['received_minor'] / 100, 2 ) ) . ' · Refunded: £' . esc_html( number_format( $balance['refunded_minor'] / 100, 2 ) ) . ' · Retained: £' . esc_html( number_format( $balance['retained_minor'] / 100, 2 ) ) . ' · Unresolved: £' . esc_html( number_format( $balance['unresolved_minor'] / 100, 2 ) ) . '</p>';
                echo '<p>Gateway refund: open the original order below and use its native refund controls. A manual refund entry alone does not transfer money. Confirm the payment-provider result. This booking must remain cancelled.</p>';
                $orders = self::orders( $b->ref );
                if ( ! is_wp_error( $orders ) ) foreach ( $orders as $order ) echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">Review/refund original order #' . (int) $order->get_id() . '</a></p>';
                if ( current_user_can( 'manage_options' ) && $row->state !== 'resolved' ) {
                    self::form( $row, 'bank_refund' ); echo '<h3>Record a bank refund already completed</h3><p><label>Amount GBP <input name="amount" type="number" min="0.01" step="0.01" required></label> <label>Transfer date <input name="date" type="date" required></label></p><p><label>Transfer reference <input name="reference" required></label> <label>Reason <input name="reason" required></label></p><p><label><input name="confirmed" type="checkbox" value="1" required> I confirm this transfer is already completed; this action does not send money.</label></p><button class="button">Record completed refund</button></form>';
                    self::form( $row, 'retain' ); echo '<h3>Record or revise a retention decision</h3><p><label>Total amount to retain GBP <input name="amount" type="number" min="0" step="0.01" required></label> <label>Reason under hire terms <input name="reason" required></label></p><button class="button">Record retention decision</button></form>';
                    self::form( $row, 'resolve' ); echo '<p><label><input type="checkbox" name="confirmed" value="1" required> I have verified refunds/retention and want to close this review.</label> <button class="button">Verify and close review</button></p></form>';
                }
            }
        }
        echo '</div></section>';
    }
    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator access required.' );
        if ( MBS_Campsite_Policy::enabled() || ! MBS_Payment_Features::enabled( 'payments' ) ) {
            echo '<div class="wrap mbs-admin"><h1>Payment expiry policy</h1><p>Automatic unpaid-hire expiry is unavailable on free/campsite sites. No bookings are enrolled.</p></div>';
            return;
        }
        echo '<div class="wrap mbs-admin"><h1>Payment expiry policy</h1><p>New approvals only. No existing bookings are enrolled. Deposits: 48 hours from approval; final cancellation cutoff: 08:00 Europe/London the day before the hire. Free, Scout, estate, offline and series billing are excluded. Scheduled processing may run after the nominal deadline if hosting cron is delayed.</p>';
        self::form( null, 'settings' ); echo '<label><input type="checkbox" name="enabled" value="1" ' . checked( self::enabled(), true, false ) . '> Enable for new paid one-off approvals on this site</label><p><button class="button button-primary">Save policy</button></p></form></div>';
    }
    public static function admin_action() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator access required.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mbs_payment_expiry' );
        $op = sanitize_key( $_POST['operation'] ?? '' );
        if ( $op === 'settings' ) {
            $enabled = ! empty( $_POST['enabled'] );
            if ( $enabled && ( MBS_Campsite_Policy::enabled() || ! MBS_Payment_Features::enabled( 'payments' ) ) ) wp_die( 'Automatic unpaid-hire expiry is unavailable on free/campsite sites.' );
            update_option( 'mbs_payment_expiry_enabled', $enabled ? 1 : 0, false );
            MBS_Audit_Log::log( 'settings', 'payment_expiry_policy', $enabled ? 'Enabled for new approvals only.' : 'Automatic payment expiry suspended.' );
            wp_safe_redirect( admin_url( 'admin.php?page=mathlin-payment-expiry' ) ); exit;
        }
        $ref = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
        $result = MBS_Space_Selection::locked( static function() use ( $ref, $op ) {
            global $wpdb;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'review_transaction', 'Could not start review.' );
            try {
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_ref=%s FOR UPDATE', $ref ) );
                if ( ! $row || (int) $row->version !== (int) ( $_POST['version'] ?? 0 ) ) throw new RuntimeException( 'Review changed. Refresh before deciding.' );
                $p = json_decode( $row->policy_json, true ); $r = json_decode( $row->review_json, true ) ?: array(); $state = $row->state;
                $reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
                if ( in_array( $op, array( 'exempt', 'extension' ), true ) && $state === 'active' && $reason !== '' ) {
                    if ( $op === 'exempt' ) $p['exempt'] = true;
                    else { $until = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', sanitize_text_field( $_POST['until'] ?? '' ), wp_timezone() ); if ( ! $until || $until->getTimestamp() <= time() ) throw new RuntimeException( 'Enter a future extension deadline.' ); $p['extension_until'] = $until->getTimestamp(); }
                    unset( $r['operational_issue'] );
                } elseif ( in_array( $op, array( 'bank_refund', 'retain', 'resolve' ), true ) && $state === 'money_review' ) {
                    $balance = self::review_balance( $row, $op === 'retain' ); if ( is_wp_error( $balance ) ) throw new RuntimeException( $balance->get_error_message() );
                    if ( $op === 'resolve' ) { if ( empty( $_POST['confirmed'] ) || $balance['unresolved_minor'] !== 0 ) throw new RuntimeException( 'Verify all refunds/retention first; unresolved money cannot be dismissed.' ); $state = 'resolved'; }
                    else {
                        $minor = MBS_Money::from_decimal_string( (string) ( $_POST['amount'] ?? '' ) );
                        if ( is_wp_error( $minor ) || $minor < ( $op === 'retain' ? 0 : 1 ) || $minor > $balance['unresolved_minor'] || $reason === '' ) throw new RuntimeException( 'Enter a valid amount within unresolved funds and a reason.' );
                        if ( $op === 'retain' ) $r['retained_minor'] = $minor;
                        else {
                            if ( empty( $_POST['confirmed'] ) ) throw new RuntimeException( 'Confirm that the bank transfer is already completed.' );
                            $evidence = MBS_Money_Held_Review::bank_refund( $minor, $balance['unresolved_minor'], sanitize_text_field( $_POST['date'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ), $reason, (int) get_current_user_id(), wp_date( 'Y-m-d' ) );
                            if ( is_wp_error( $evidence ) ) throw new RuntimeException( $evidence->get_error_message() );
                            foreach ( $r['bank_refunds'] ?? array() as $prior ) if ( $prior['reference'] === $evidence['reference'] ) throw new RuntimeException( 'This transfer reference is already recorded.' );
                            $r['bank_refunds'][] = $evidence;
                        }
                    }
                } else throw new RuntimeException( 'That action is not valid in this review state.' );
                $r['decisions'][] = array( 'operation' => $op, 'reason' => $reason, 'reviewer' => get_current_user_id(), 'at' => time(), 'amount_minor' => in_array( $op, array( 'retain', 'bank_refund' ), true ) ? $minor : null, 'prior_retained_minor' => (int) ( ( json_decode( $row->review_json, true ) ?: array() )['retained_minor'] ?? 0 ) );
                if ( ! self::save( $row, $state, $p, $r ) || ! MBS_Audit_Log::log( $ref, 'payment_policy_review', 'Manual review action: ' . $op . '. ' . $reason . ' Booking/payment receipt history was not rewritten.' ) ) throw new RuntimeException( 'Could not save review/audit.' );
                if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit review.' );
                return true;
            } catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'review_failed', $e->getMessage() ); }
        } );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ) );
        wp_safe_redirect( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $ref ) ) ); exit;
    }
}
