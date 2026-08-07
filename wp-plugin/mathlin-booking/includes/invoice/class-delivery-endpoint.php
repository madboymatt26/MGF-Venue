<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Authenticated invoice document delivery endpoint.
 *
 * Serves invoice documents (HTML inline view or PDF download) on demand
 * by regenerating from the immutable snapshot. Never serves static files.
 *
 * Security:
 * - Admin: mbs_manage_bookings capability
 * - Hirer: WordPress user session + user_id ownership match
 * - Guest: opaque signed token (time-bounded, revocable, single-document)
 * - All: nonce validation, access logging
 */
class MBS_Invoice_Delivery_Endpoint {

    public function init() {
        add_action( 'wp_ajax_mbs_invoice_document', array( $this, 'handle_request' ) );
        add_action( 'wp_ajax_nopriv_mbs_invoice_document', array( $this, 'handle_guest_request' ) );
    }

    /**
     * Handle authenticated (logged-in) document requests.
     */
    public function handle_request() {
        // Validate nonce
        if ( ! check_ajax_referer( 'mbs_invoice_document_nonce', 'nonce', false ) ) {
            self::log_access( 0, '', 'view', 'denied_nonce' );
            wp_send_json_error( 'Invalid security token.', 403 );
        }

        $document_id = absint( $_REQUEST['document_id'] ?? 0 );
        $format = sanitize_key( $_REQUEST['format'] ?? 'html' );
        $mode = sanitize_key( $_REQUEST['mode'] ?? 'issued' );

        if ( ! $document_id || ! in_array( $format, array( 'html', 'pdf' ), true ) ) {
            wp_send_json_error( 'Invalid request parameters.', 400 );
        }

        if ( ! in_array( $mode, array( 'issued', 'current_account' ), true ) ) {
            $mode = 'issued';
        }

        // Authorisation
        $user_id = get_current_user_id();
        $authorised = self::check_authorisation( $document_id, $user_id );
        if ( is_wp_error( $authorised ) ) {
            self::log_access( $user_id, "doc:{$document_id}", $format, 'denied' );
            wp_send_json_error( $authorised->get_error_message(), 403 );
        }

        // Build and render
        $view_model = MBS_Invoice_Document_Builder::build_from_document( $document_id, $mode );
        if ( is_wp_error( $view_model ) ) {
            self::log_access( $user_id, "doc:{$document_id}", $format, 'error' );
            wp_send_json_error( $view_model->get_error_message(), 500 );
        }

        self::log_access( $user_id, "doc:{$document_id}", $format, 'success' );

        if ( $format === 'pdf' ) {
            self::serve_pdf( $view_model, $document_id );
        } else {
            self::serve_html( $view_model );
        }
    }

    /**
     * Handle unauthenticated (guest) document requests via signed token.
     */
    public function handle_guest_request() {
        $token = sanitize_text_field( $_REQUEST['token'] ?? '' );
        $document_id = absint( $_REQUEST['document_id'] ?? 0 );
        $format = sanitize_key( $_REQUEST['format'] ?? 'pdf' );

        if ( ! $token || ! $document_id ) {
            wp_die( 'Invalid download link.', 'Access Denied', array( 'response' => 400 ) );
        }

        // Validate token
        $valid = self::validate_guest_token( $token, $document_id );
        if ( is_wp_error( $valid ) ) {
            self::log_access( 0, "doc:{$document_id}", $format, 'denied_token' );
            wp_die( 'This download link has expired or is invalid.', 'Access Denied', array( 'response' => 403 ) );
        }

        // Build (always issued mode for guest access)
        $view_model = MBS_Invoice_Document_Builder::build_from_document( $document_id, 'issued' );
        if ( is_wp_error( $view_model ) ) {
            wp_die( 'Document could not be generated.', 'Error', array( 'response' => 500 ) );
        }

        self::log_access( 0, "doc:{$document_id}", $format, 'success_guest' );

        if ( $format === 'pdf' ) {
            self::serve_pdf( $view_model, $document_id );
        } else {
            self::serve_html( $view_model );
        }
    }

    // ── Authorisation ──────────────────────────────────────────────────────────

    /**
     * Check if the current user is authorised to access a document.
     *
     * @param int $document_id
     * @param int $user_id
     * @return true|WP_Error
     */
    private static function check_authorisation( $document_id, $user_id ) {
        // Admin: mbs_manage_bookings capability
        if ( current_user_can( 'mbs_manage_bookings' ) ) {
            return true;
        }

        // Hirer: must own the document
        if ( ! $user_id ) {
            return new WP_Error( 'not_authorised', 'You must be logged in to access this document.' );
        }

        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $document = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$doc_table} WHERE id = %d", $document_id ) );
        if ( ! $document ) {
            return new WP_Error( 'not_found', 'Document not found.' );
        }

        // Check ownership via booking
        if ( $document->booking_id ) {
            $booking_table = $wpdb->prefix . MBS_TABLE;
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, email FROM {$booking_table} WHERE id = %d", (int) $document->booking_id ) );
            if ( $booking ) {
                // Primary: user_id match
                if ( (int) $booking->user_id === $user_id ) return true;
                // Legacy fallback: email match (audited)
                $user = get_userdata( $user_id );
                if ( $user && strtolower( $user->user_email ) === strtolower( $booking->email ) ) {
                    error_log( "[MGF Venue] Invoice access via email fallback: user {$user_id}, doc {$document_id}" );
                    return true;
                }
            }
        }

        // Check ownership via ledger invoice → series
        if ( $document->invoice_id ) {
            $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
            $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT series_ref, contact_email FROM {$invoice_table} WHERE id = %d", (int) $document->invoice_id ) );
            if ( $invoice && $invoice->series_ref ) {
                $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
                $series = $wpdb->get_row( $wpdb->prepare( "SELECT contact_email FROM {$series_table} WHERE series_ref = %s", $invoice->series_ref ) );
                if ( $series ) {
                    $user = get_userdata( $user_id );
                    if ( $user && strtolower( $user->user_email ) === strtolower( $series->contact_email ) ) {
                        return true;
                    }
                }
            }
        }

        return new WP_Error( 'not_authorised', 'You do not have permission to access this document.' );
    }

    // ── Guest Token ────────────────────────────────────────────────────────────

    /**
     * Create a guest download token for a document.
     *
     * @param int $document_id
     * @param int $ttl_seconds  Token lifetime (default 72 hours).
     * @return string|WP_Error  The raw token (to include in URL).
     */
    public static function create_guest_token( $document_id, $ttl_seconds = 259200 ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_DOWNLOAD_TOKENS_TABLE;

        try {
            $raw_token = bin2hex( random_bytes( 32 ) );
        } catch ( \Exception $e ) {
            $raw_token = wp_generate_password( 64, false, false );
        }

        $token_hash = hash( 'sha256', $raw_token );
        $expires_at = wp_date( 'Y-m-d H:i:s', time() + $ttl_seconds );

        $inserted = $wpdb->insert( $table, array(
            'token_hash'  => $token_hash,
            'document_id' => (int) $document_id,
            'expires_at'  => $expires_at,
            'max_uses'    => 5,
            'created_at'  => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            return new WP_Error( 'token_create_failed', 'Could not create the download token.' );
        }

        return $raw_token;
    }

    /**
     * Validate a guest download token.
     */
    private static function validate_guest_token( $raw_token, $document_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_DOWNLOAD_TOKENS_TABLE;
        $token_hash = hash( 'sha256', $raw_token );

        $token = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE token_hash = %s AND document_id = %d",
            $token_hash, (int) $document_id
        ) );

        if ( ! $token ) {
            return new WP_Error( 'token_invalid', 'Token not found.' );
        }

        if ( $token->revoked_at ) {
            return new WP_Error( 'token_revoked', 'This download link has been revoked.' );
        }

        if ( strtotime( $token->expires_at ) < time() ) {
            return new WP_Error( 'token_expired', 'This download link has expired.' );
        }

        if ( $token->max_uses && (int) $token->use_count >= (int) $token->max_uses ) {
            return new WP_Error( 'token_exhausted', 'This download link has reached its maximum use count.' );
        }

        // Increment use count
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET use_count = use_count + 1 WHERE id = %d",
            (int) $token->id
        ) );

        return true;
    }

    // ── Serve Documents ────────────────────────────────────────────────────────

    private static function serve_html( $view_model ) {
        $html = MBS_HTML_Renderer::render( $view_model );
        if ( is_wp_error( $html ) ) {
            wp_send_json_error( $html->get_error_message(), 500 );
        }
        // Return HTML as JSON content for AJAX iframe/modal loading
        wp_send_json_success( array( 'html' => $html ) );
    }

    private static function serve_pdf( $view_model, $document_id ) {
        // Generate HTML first
        $html = MBS_HTML_Renderer::render( $view_model );
        if ( is_wp_error( $html ) ) {
            wp_send_json_error( $html->get_error_message(), 500 );
        }

        // PDF rendering — placeholder until Stage 6 Dompdf integration
        // For now, serve the HTML as a downloadable file
        $invoice_number = $view_model->snapshot->invoice_number ?? 'invoice';
        $filename = sanitize_file_name( 'invoice-' . $invoice_number . '.html' );

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: private, no-cache, no-store' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $html;
        exit;
    }

    // ── Access Logging ─────────────────────────────────────────────────────────

    private static function log_access( $user_id, $ref, $action, $result ) {
        MBS_Audit_Log::log( $ref ?: 'system', 'invoice_document_access', "User {$user_id}: {$action} → {$result}" );
    }
}
