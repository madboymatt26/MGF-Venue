<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Content-addressed immutable logo asset store.
 *
 * Organisation logos are validated, hashed, and stored once. Multiple invoice
 * snapshots reference the same asset by ID and hash. Assets are never modified
 * or deleted while any document references them.
 */
class MBS_Logo_Asset {

    const MAX_FILE_SIZE = 524288;    // 512 KB
    const MAX_WIDTH     = 1024;
    const MAX_HEIGHT    = 1024;
    const ALLOWED_MIMES = array( 'image/png', 'image/jpeg', 'image/gif' );

    /**
     * Validate and store a logo from a local file path.
     * Returns the asset row (existing or newly created) or WP_Error.
     *
     * @param string $file_path Absolute local file path (not a URL).
     * @return object|WP_Error Asset row object with id, content_hash, mime_type, width, height, file_size.
     */
    public static function store_from_file( $file_path ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'logo_file_not_found', 'The logo file does not exist or is not readable.' );
        }

        $file_size = filesize( $file_path );
        if ( $file_size > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'logo_too_large', sprintf( 'Logo file exceeds %d KB limit.', self::MAX_FILE_SIZE / 1024 ) );
        }

        // Validate MIME type from actual file content (not extension)
        $finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : null;
        if ( $finfo ) {
            $mime_type = finfo_file( $finfo, $file_path );
            finfo_close( $finfo );
        } else {
            $mime_type = mime_content_type( $file_path );
        }

        if ( ! in_array( $mime_type, self::ALLOWED_MIMES, true ) ) {
            return new WP_Error( 'logo_invalid_mime', 'Logo must be PNG, JPEG or GIF. SVG is not permitted.' );
        }

        // Validate dimensions
        $image_info = getimagesize( $file_path );
        if ( ! $image_info ) {
            return new WP_Error( 'logo_invalid_image', 'The file is not a valid image.' );
        }

        $width = (int) $image_info[0];
        $height = (int) $image_info[1];

        if ( $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT ) {
            return new WP_Error( 'logo_too_large_dimensions', sprintf(
                'Logo dimensions (%dx%d) exceed %dx%d maximum.',
                $width, $height, self::MAX_WIDTH, self::MAX_HEIGHT
            ) );
        }

        if ( $width < 1 || $height < 1 ) {
            return new WP_Error( 'logo_zero_dimensions', 'Logo has invalid dimensions.' );
        }

        // Read content and compute hash
        $content = file_get_contents( $file_path );
        if ( $content === false ) {
            return new WP_Error( 'logo_read_failed', 'Could not read the logo file.' );
        }

        $content_hash = hash( 'sha256', $content );

        // Check if this exact asset already exists (content-addressed dedup)
        global $wpdb;
        $table = $wpdb->prefix . MBS_DOCUMENT_ASSETS_TABLE;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, content_hash, mime_type, width, height, file_size FROM {$table} WHERE content_hash = %s",
            $content_hash
        ) );

        if ( $existing ) {
            return $existing;
        }

        // Insert new asset
        $inserted = $wpdb->insert( $table, array(
            'content_hash' => $content_hash,
            'mime_type'    => $mime_type,
            'width'        => $width,
            'height'       => $height,
            'file_size'    => $file_size,
            'content'      => $content,
            'created_at'   => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            // Race condition: another process may have inserted the same hash
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, content_hash, mime_type, width, height, file_size FROM {$table} WHERE content_hash = %s",
                $content_hash
            ) );
            if ( $existing ) return $existing;
            return new WP_Error( 'logo_store_failed', 'Could not store the logo asset.' );
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, content_hash, mime_type, width, height, file_size FROM {$table} WHERE id = %d",
            (int) $wpdb->insert_id
        ) );
    }

    /**
     * Get a logo asset by ID, verifying its content hash.
     *
     * @param int    $asset_id     The asset primary key.
     * @param string $expected_hash Expected SHA-256 hash for integrity verification.
     * @return object|WP_Error Asset row (with content) or error.
     */
    public static function get_verified( $asset_id, $expected_hash ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_DOCUMENT_ASSETS_TABLE;

        $asset = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            (int) $asset_id
        ) );

        if ( ! $asset ) {
            return new WP_Error( 'logo_asset_not_found', 'The referenced logo asset does not exist.' );
        }

        if ( ! hash_equals( $asset->content_hash, $expected_hash ) ) {
            return new WP_Error( 'logo_asset_corrupted', 'Logo asset content hash does not match the snapshot reference.' );
        }

        return $asset;
    }

    /**
     * Get the logo as a base64 data URI for HTML embedding.
     * Used by renderers to embed the logo without external file references.
     *
     * @param int    $asset_id     The asset primary key.
     * @param string $expected_hash Expected hash for verification.
     * @return string|WP_Error Data URI string or error.
     */
    public static function get_data_uri( $asset_id, $expected_hash ) {
        $asset = self::get_verified( $asset_id, $expected_hash );
        if ( is_wp_error( $asset ) ) return $asset;

        return 'data:' . $asset->mime_type . ';base64,' . base64_encode( $asset->content );
    }

    /**
     * Resolve the current organisation logo and return its asset ID + hash.
     * Call this BEFORE opening a database transaction.
     *
     * @return array|null ['asset_id' => int, 'content_hash' => string] or null if no logo configured.
     */
    public static function resolve_current_org_logo() {
        // Try WordPress attachment ID first (most common WordPress pattern)
        $logo_attachment_id = (int) get_option( 'mbs_org_logo_attachment_id', 0 );
        if ( $logo_attachment_id ) {
            $file_path = get_attached_file( $logo_attachment_id );
            if ( $file_path && file_exists( $file_path ) ) {
                $asset = self::store_from_file( $file_path );
                if ( ! is_wp_error( $asset ) ) {
                    return array( 'asset_id' => (int) $asset->id, 'content_hash' => $asset->content_hash );
                }
            }
        }

        // Try direct file path option
        $logo_path = get_option( 'mbs_org_logo_path', '' );
        if ( $logo_path && file_exists( $logo_path ) ) {
            $asset = self::store_from_file( $logo_path );
            if ( ! is_wp_error( $asset ) ) {
                return array( 'asset_id' => (int) $asset->id, 'content_hash' => $asset->content_hash );
            }
        }

        // Try logo URL option and resolve to local path
        $logo_url = get_option( 'mbs_org_logo', '' );
        if ( $logo_url ) {
            // Attempt to resolve URL to local file via attachment
            $attachment_id = attachment_url_to_postid( $logo_url );
            if ( $attachment_id ) {
                $file_path = get_attached_file( $attachment_id );
                if ( $file_path && file_exists( $file_path ) ) {
                    $asset = self::store_from_file( $file_path );
                    if ( ! is_wp_error( $asset ) ) {
                        return array( 'asset_id' => (int) $asset->id, 'content_hash' => $asset->content_hash );
                    }
                }
            }
        }

        return null; // No logo configured or resolvable
    }
}
