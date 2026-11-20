<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Immutable one-off acceptance evidence. Never invents historical consent. */
class MBS_Terms_Acceptance {
    public static function current() {
        $page_id = (int) get_option( 'mbs_terms_page_id', 0 );
        $page = $page_id ? get_post( $page_id ) : null;
        $terms = (string) get_option( 'mbs_terms_text', '' );
        if ( $terms === '' ) $terms = MBS_Bookings::get_default_terms();
        $terms = wp_kses_post( MBS_Bookings::parse_venue_placeholders( $terms ) );
        $page_text = $page ? wp_kses_post( $page->post_content ) : '';
        $snapshot = array( 'page_id' => $page_id, 'page_content' => $page_text, 'terms_html' => $terms );
        $snapshot['hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
        return $snapshot;
    }
    public static function capture( $hash ) {
        $snapshot = self::current();
        if ( ! is_string( $hash ) || ! hash_equals( $snapshot['hash'], $hash ) ) return new WP_Error( 'terms_changed', 'The hire terms have changed or this form is out of date. Refresh, review the terms and submit again.' );
        $snapshot['accepted_at'] = gmdate( DATE_RFC3339 );
        $snapshot['source'] = 'hirer_request';
        return $snapshot;
    }
    /** Stored acceptance evidence for a booking, or null when none was recorded. */
    public static function evidence( $booking ) {
        $meta = json_decode( (string) ( $booking->custom_fields ?? '{}' ), true ) ?: array();
        $evidence = $meta['_mbs_terms_acceptance'] ?? null;
        return is_array( $evidence ) ? $evidence : null;
    }
    /** Card-body content for the unified "Hire terms & acceptance" admin card. */
    public static function render( $booking ) {
        $evidence = self::evidence( $booking );
        echo '<div class="mbs-terms-acceptance">';
        if ( ! $evidence ) { echo '<p>No versioned hirer acceptance was recorded for this booking. Imported and staff-entered bookings are not retrospectively marked accepted.</p></div>'; return; }
        echo '<p>Accepted ' . esc_html( $evidence['accepted_at'] ?? '' ) . '; version <code class="mbs-terms-hash">' . esc_html( $evidence['hash'] ?? '' ) . '</code></p><details class="mbs-inline-disclosure"><summary>View the terms snapshot</summary><div class="nms-terms-content">' . wp_kses_post( $evidence['terms_html'] ?? '' ) . '</div>';
        if ( ! empty( $evidence['page_content'] ) ) echo '<h3 class="mbs-terms-source-heading">Linked terms page source</h3><pre class="mbs-terms-source">' . esc_html( $evidence['page_content'] ) . '</pre>';
        echo '</details></div>';
    }
}
