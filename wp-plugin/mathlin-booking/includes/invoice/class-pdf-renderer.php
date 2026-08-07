<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PDF Invoice Renderer using Dompdf.
 *
 * Consumes the same HTML produced by MBS_HTML_Renderer and converts it to PDF.
 * Uses defensive configuration: no remote loading, no PHP/JS execution,
 * restrictive chroot, new instance per document.
 *
 * Error contract: returns string (PDF binary) or WP_Error.
 * Fatal PHP termination (memory exhaustion) is NOT catchable in-process;
 * it is handled by the email queue worker's lease-based stale recovery.
 */
class MBS_PDF_Renderer {

    const MAX_LINE_ITEMS = 200;
    const MAX_DESCRIPTION_LENGTH = 500;
    const MAX_PAGES = 20;
    const MAX_PDF_BYTES = 5242880; // 5 MB
    const RENDER_MEMORY_LIMIT = '128M';
    const RENDER_TIMEOUT = 30; // seconds

    /**
     * Render an invoice document as a PDF binary string.
     *
     * @param MBS_Invoice_Document_View_Model $model
     * @return string|WP_Error  PDF binary on success, WP_Error on recoverable failure.
     */
    public static function render( $model ) {
        // Preflight validation
        $preflight = self::preflight_check( $model );
        if ( is_wp_error( $preflight ) ) return $preflight;

        // Generate HTML (same shared renderer)
        $html = MBS_HTML_Renderer::render( $model );
        if ( is_wp_error( $html ) ) return $html;

        // Ensure autoloader is loaded
        $autoload = MBS_PLUGIN_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            return new WP_Error( 'pdf_library_missing', 'PDF rendering library not installed. Run composer install.' );
        }
        require_once $autoload;

        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            return new WP_Error( 'pdf_library_unavailable', 'Dompdf class not found after autoloading.' );
        }

        // Set resource limits
        $prev_memory = ini_get( 'memory_limit' );
        $prev_time = ini_get( 'max_execution_time' );
        @ini_set( 'memory_limit', self::RENDER_MEMORY_LIMIT );
        @set_time_limit( self::RENDER_TIMEOUT );

        try {
            // Defensive Dompdf configuration — new instance per document
            $options = new \Dompdf\Options();
            $options->setIsRemoteEnabled( false );       // SSRF prevention
            $options->setIsPhpEnabled( false );          // No PHP in HTML
            $options->setIsJavascriptEnabled( false );   // No JS
            $options->setIsFontSubsettingEnabled( true );
            $options->setDefaultFont( 'sans-serif' );

            // Temp/cache paths
            $temp_dir = self::get_render_temp_dir();
            if ( $temp_dir ) {
                $options->setTempDir( $temp_dir );
                $options->setFontCache( $temp_dir );
            }

            // Restrictive chroot — only the temp dir (logos embedded as data URIs)
            if ( $temp_dir ) {
                $options->setChroot( $temp_dir );
            }

            $options->setLogOutputFile( '' ); // No log file

            $dompdf = new \Dompdf\Dompdf( $options );
            $dompdf->loadHtml( $html );
            $dompdf->setPaper( 'A4', 'portrait' );
            $dompdf->render();

            // Page count check
            $page_count = $dompdf->getCanvas()->get_page_count();
            if ( $page_count > self::MAX_PAGES ) {
                return new WP_Error( 'pdf_too_many_pages', sprintf(
                    'Rendered PDF has %d pages (maximum %d). Consider splitting the invoice.',
                    $page_count, self::MAX_PAGES
                ) );
            }

            $pdf_binary = $dompdf->output();

            // Size check
            $pdf_size = strlen( $pdf_binary );
            if ( $pdf_size > self::MAX_PDF_BYTES ) {
                return new WP_Error( 'pdf_too_large', sprintf(
                    'Rendered PDF is %s (maximum %s).',
                    size_format( $pdf_size ), size_format( self::MAX_PDF_BYTES )
                ) );
            }

            return $pdf_binary;

        } catch ( \Exception $e ) {
            return new WP_Error( 'pdf_render_failed', 'PDF rendering failed: ' . $e->getMessage() );
        } finally {
            // Restore resource limits
            @ini_set( 'memory_limit', $prev_memory );
            @set_time_limit( (int) $prev_time );
        }
    }

    /**
     * Render a PDF and save to a temporary file.
     * Used by the email queue worker for wp_mail() attachments.
     *
     * @param int $document_id  Document primary key.
     * @return string|WP_Error  Temp file path on success.
     */
    public static function render_to_temp_file( $document_id ) {
        $model = MBS_Invoice_Document_Builder::build_from_document( (int) $document_id, 'issued' );
        if ( is_wp_error( $model ) ) return $model;

        $pdf = self::render( $model );
        if ( is_wp_error( $pdf ) ) return $pdf;

        $temp_dir = self::get_render_temp_dir();
        if ( ! $temp_dir ) {
            return new WP_Error( 'pdf_temp_dir_unavailable', 'No suitable temporary directory available for PDF rendering.' );
        }

        try {
            $filename = 'mbs_' . bin2hex( random_bytes( 16 ) ) . '.pdf';
        } catch ( \Exception $e ) {
            $filename = 'mbs_' . md5( uniqid( '', true ) . wp_rand() ) . '.pdf';
        }

        $temp_path = $temp_dir . '/' . $filename;
        $written = file_put_contents( $temp_path, $pdf, LOCK_EX );

        if ( $written === false ) {
            return new WP_Error( 'pdf_write_failed', 'Could not write PDF to temporary file.' );
        }

        @chmod( $temp_path, 0600 );
        return $temp_path;
    }

    // ── Preflight ──────────────────────────────────────────────────────────────

    /**
     * Preflight validation before attempting render.
     */
    private static function preflight_check( $model ) {
        if ( ! $model || ! $model->snapshot ) {
            return new WP_Error( 'pdf_no_model', 'No document model for PDF rendering.' );
        }

        $line_count = count( $model->snapshot->line_items );
        if ( $line_count > self::MAX_LINE_ITEMS ) {
            return new WP_Error( 'pdf_too_many_items', sprintf(
                'Invoice has %d line items (maximum %d). Split into multiple invoices.',
                $line_count, self::MAX_LINE_ITEMS
            ) );
        }

        // Check description lengths
        foreach ( $model->snapshot->line_items as $item ) {
            $desc = $item['description'] ?? '';
            if ( mb_strlen( $desc ) > self::MAX_DESCRIPTION_LENGTH ) {
                return new WP_Error( 'pdf_description_too_long', sprintf(
                    'A line item description exceeds %d characters.',
                    self::MAX_DESCRIPTION_LENGTH
                ) );
            }
        }

        // Verify required PHP extensions
        if ( ! extension_loaded( 'dom' ) ) {
            return new WP_Error( 'pdf_ext_missing', 'The PHP DOM extension is required for PDF rendering.' );
        }
        if ( ! extension_loaded( 'mbstring' ) ) {
            return new WP_Error( 'pdf_ext_missing', 'The PHP MBString extension is required for PDF rendering.' );
        }

        return true;
    }

    // ── Temporary Directory ────────────────────────────────────────────────────

    /**
     * Get a suitable temp directory for PDF renders.
     * Prefers OS temp (outside web root), falls back to a private WP directory.
     */
    private static function get_render_temp_dir() {
        // OS temp directory (typically /tmp on Linux — outside web root)
        $os_temp = get_temp_dir();
        $render_dir = rtrim( $os_temp, '/' ) . '/mbs-invoice-render';

        if ( wp_mkdir_p( $render_dir ) && is_writable( $render_dir ) ) {
            return $render_dir;
        }

        // Fallback: protected dir under WP_CONTENT
        $fallback = WP_CONTENT_DIR . '/mbs-private/render';
        if ( wp_mkdir_p( $fallback ) ) {
            // Write protection files
            $htaccess = $fallback . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
            }
            $index = $fallback . '/index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, '<?php // Silence is golden.' );
            }
            if ( is_writable( $fallback ) ) return $fallback;
        }

        return null;
    }

    /**
     * Clean up orphaned render files older than 1 hour.
     * Called by the email queue cleanup cron.
     */
    public static function cleanup_orphans() {
        $dir = self::get_render_temp_dir();
        if ( ! $dir || ! is_dir( $dir ) ) return;

        $cutoff = time() - 3600; // 1 hour
        $files = glob( $dir . '/mbs_*.pdf' );
        if ( ! is_array( $files ) ) return;

        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                @unlink( $file );
            }
        }
    }
}
