<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Invoice Document Security — input validation, limits, and sanitisation.
 *
 * Enforces pre-issuance limits, validates inputs before rendering,
 * and provides sanitisation helpers for the document domain.
 */
class MBS_Invoice_Document_Security {

    const MAX_LINE_ITEMS = 200;
    const MAX_DESCRIPTION_LENGTH = 500;
    const MAX_INVOICE_NUMBER_LENGTH = 50;
    const MAX_NAME_LENGTH = 200;
    const MAX_ADDRESS_LENGTH = 500;
    const MAX_EMAIL_LENGTH = 254;
    const MAX_TERMS = 12;
    const EMAIL_ATTACHMENT_MAX_BYTES = 5242880; // 5 MB

    /**
     * Validate line items before invoice issuance.
     * Prevents creation of invoices that could never be rendered.
     *
     * @param array $line_items Array of line item arrays.
     * @return true|WP_Error
     */
    public static function validate_line_items_for_issuance( $line_items ) {
        if ( ! is_array( $line_items ) ) {
            return new WP_Error( 'invalid_line_items', 'Line items must be an array.' );
        }

        if ( count( $line_items ) > self::MAX_LINE_ITEMS ) {
            return new WP_Error( 'too_many_line_items', sprintf(
                'Invoice exceeds the maximum of %d line items. Split into multiple invoices.',
                self::MAX_LINE_ITEMS
            ) );
        }

        foreach ( $line_items as $idx => $item ) {
            $desc = $item['description'] ?? '';
            if ( mb_strlen( $desc ) > self::MAX_DESCRIPTION_LENGTH ) {
                return new WP_Error( 'description_too_long', sprintf(
                    'Line item %d description exceeds %d characters.',
                    $idx + 1, self::MAX_DESCRIPTION_LENGTH
                ) );
            }
            // Check for control characters (except newline/tab)
            if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $desc ) ) {
                return new WP_Error( 'invalid_characters', 'Line item descriptions must not contain control characters.' );
            }
        }

        return true;
    }

    /**
     * Validate term schedule for termly billing.
     *
     * @param array  $terms        Term array [{label, start, end}].
     * @param string $series_start Series start date Y-m-d.
     * @param string $series_end   Series repeat_until date Y-m-d.
     * @return true|WP_Error
     */
    public static function validate_term_schedule( $terms, $series_start = '', $series_end = '' ) {
        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return new WP_Error( 'terms_empty', 'At least one term period is required for termly billing.' );
        }

        if ( count( $terms ) > self::MAX_TERMS ) {
            return new WP_Error( 'too_many_terms', sprintf( 'Maximum %d term periods allowed.', self::MAX_TERMS ) );
        }

        $keys_seen = array();
        $ranges = array();

        foreach ( $terms as $idx => $term ) {
            $label = trim( $term['label'] ?? '' );
            $start = trim( $term['start'] ?? '' );
            $end   = trim( $term['end'] ?? '' );
            $key   = trim( $term['key'] ?? 'term_' . ( $idx + 1 ) );

            // Non-empty label
            if ( $label === '' ) {
                return new WP_Error( 'term_label_empty', 'Term ' . ( $idx + 1 ) . ' requires a name.' );
            }

            // Valid date formats
            if ( ! self::is_valid_date( $start ) ) {
                return new WP_Error( 'term_start_invalid', 'Term "' . $label . '" has an invalid start date.' );
            }
            if ( ! self::is_valid_date( $end ) ) {
                return new WP_Error( 'term_end_invalid', 'Term "' . $label . '" has an invalid end date.' );
            }

            // Start <= end
            if ( $start > $end ) {
                return new WP_Error( 'term_dates_reversed', 'Term "' . $label . '" start date must be on or before end date.' );
            }

            // No duplicate keys
            if ( isset( $keys_seen[ $key ] ) ) {
                return new WP_Error( 'term_duplicate_key', 'Duplicate term key: ' . $key );
            }
            $keys_seen[ $key ] = true;

            // Compatibility with series date range
            if ( $series_start && $end < $series_start ) {
                return new WP_Error( 'term_before_series', 'Term "' . $label . '" ends before the series starts.' );
            }
            if ( $series_end && $start > $series_end ) {
                return new WP_Error( 'term_after_series', 'Term "' . $label . '" starts after the series ends.' );
            }

            $ranges[] = array( 'start' => $start, 'end' => $end, 'label' => $label );
        }

        // Check for overlapping periods
        usort( $ranges, function( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );
        for ( $i = 1; $i < count( $ranges ); $i++ ) {
            if ( $ranges[ $i ]['start'] <= $ranges[ $i - 1 ]['end'] ) {
                return new WP_Error( 'terms_overlap', 'Term "' . $ranges[ $i ]['label'] . '" overlaps with "' . $ranges[ $i - 1 ]['label'] . '".' );
            }
        }

        return true;
    }

    /**
     * Validate a document request before rendering.
     *
     * @param int $document_id
     * @return true|WP_Error
     */
    public static function validate_document_request( $document_id ) {
        if ( ! $document_id || ! is_numeric( $document_id ) || (int) $document_id < 1 ) {
            return new WP_Error( 'invalid_document_id', 'Invalid document identifier.' );
        }
        return true;
    }

    /**
     * Validate a booking reference format.
     *
     * @param string $ref
     * @return true|WP_Error
     */
    public static function validate_ref_format( $ref ) {
        if ( ! preg_match( '/^[A-Z0-9\-]{4,30}$/i', $ref ) ) {
            return new WP_Error( 'invalid_ref_format', 'Invalid reference format.' );
        }
        return true;
    }

    /**
     * Sanitise customer-controlled text for snapshot storage.
     * Preserves legal content but strips control characters.
     * Does NOT destructively alter names/addresses.
     *
     * @param string $text
     * @param int    $max_length
     * @return string
     */
    public static function sanitise_snapshot_text( $text, $max_length = 500 ) {
        // Strip control characters except newline and tab
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $text );
        // Enforce length limit
        if ( mb_strlen( $text ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length );
        }
        return $text;
    }

    /**
     * Check if a rendered PDF exceeds the email attachment threshold.
     *
     * @param string $pdf_binary
     * @return bool True if oversized (should use download link instead).
     */
    public static function exceeds_email_attachment_limit( $pdf_binary ) {
        $limit = (int) get_option( 'mbs_email_attachment_max_bytes', self::EMAIL_ATTACHMENT_MAX_BYTES );
        return strlen( $pdf_binary ) > $limit;
    }

    /**
     * Validate a date string is real Y-m-d.
     */
    private static function is_valid_date( $value ) {
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }
}
