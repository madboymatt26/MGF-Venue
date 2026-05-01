<?php
/**
 * GitHub-based self-updater for the Mathlin Booking plugin.
 *
 * Hooks into WordPress's native update system so that new GitHub releases
 * appear in Dashboard → Updates just like any plugin from wordpress.org.
 *
 * Usage:
 *   1. Store a GitHub Personal Access Token in wp-admin → Scout Bookings → Settings.
 *   2. When you're ready to release, create a GitHub Release with a tag like "1.0.1"
 *      and attach a zip of the plugin folder (or let GitHub auto-generate the source zip).
 *   3. WordPress will detect the new version and offer a one-click update.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Updater {

    /** GitHub owner/repo */
    private $repo = 'madboymatt26/mathlin-booking';

    /** Path inside the repo where the plugin lives */
    private $repo_subdir = 'wp-plugin/mathlin-booking';

    /** Plugin basename (e.g. mathlin-booking/mathlin-booking.php) */
    private $plugin_basename;

    /** Current installed version */
    private $current_version;

    /** Plugin slug */
    private $slug = 'mathlin-booking';

    /** Cached GitHub API response */
    private $github_response = null;

    public function __construct() {
        $this->plugin_basename = plugin_basename( MBS_PLUGIN_DIR . 'mathlin-booking.php' );
        $this->current_version = MBS_VERSION;
    }

    /**
     * Register all hooks.
     */
    public function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection',             array( $this, 'fix_source_dir' ), 10, 4 );
    }

    /**
     * Get the stored GitHub token.
     */
    private function get_token() {
        return get_option( 'mbs_github_token', '' );
    }

    /**
     * Fetch the latest release from GitHub.
     */
    private function fetch_latest_release() {
        if ( $this->github_response !== null ) {
            return $this->github_response;
        }

        $url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'MathlinBookingUpdater/' . $this->current_version,
            ),
        );

        $token = $this->get_token();
        if ( ! empty( $token ) ) {
            $args['headers']['Authorization'] = 'token ' . $token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->github_response = false;
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $this->github_response = false;
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body ) || ! isset( $body['tag_name'] ) ) {
            $this->github_response = false;
            return false;
        }

        $this->github_response = $body;
        return $body;
    }

    /**
     * Get the version string from a GitHub release tag.
     * Strips a leading "v" if present (e.g. "v1.0.1" → "1.0.1").
     */
    private function parse_version( $tag ) {
        return ltrim( $tag, 'vV' );
    }

    /**
     * Build the download URL for the release zip.
     * Prefers an attached zip asset; falls back to the auto-generated zipball.
     */
    private function get_download_url( $release ) {
        // Check for an attached .zip asset first
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( substr( $asset['name'], -4 ) === '.zip' ) {
                    $url = $asset['url']; // API URL, needs Accept header
                    $token = $this->get_token();
                    if ( ! empty( $token ) ) {
                        // Use the browser_download_url with token as query param
                        // for private repos this is more reliable
                        return add_query_arg( 'access_token', $token, $asset['browser_download_url'] );
                    }
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback: GitHub auto-generated source zipball
        $url = $release['zipball_url'];
        $token = $this->get_token();
        if ( ! empty( $token ) ) {
            return add_query_arg( 'access_token', $token, $url );
        }
        return $url;
    }

    /**
     * Hook: check for plugin updates.
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = $this->parse_version( $release['tag_name'] );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $download_url = $this->get_download_url( $release );

            // For private repos, we need to add auth headers to the download
            $token = $this->get_token();
            if ( ! empty( $token ) ) {
                // Use the zipball URL with token-based auth via a filter
                add_filter( 'http_request_args', array( $this, 'add_auth_header' ), 10, 2 );
            }

            $transient->response[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote_version,
                'url'         => $release['html_url'],
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires'    => '5.0',
                'requires_php'=> '7.4',
            );
        }

        return $transient;
    }

    /**
     * Hook: provide plugin info for the "View Details" popup.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = $this->parse_version( $release['tag_name'] );

        $info = (object) array(
            'name'            => 'Mathlin Booking System',
            'slug'            => $this->slug,
            'version'         => $remote_version,
            'author'          => '<a href="https://github.com/madboymatt26">Needham Market Scout Group</a>',
            'homepage'        => 'https://github.com/' . $this->repo,
            'requires'        => '5.0',
            'requires_php'    => '7.4',
            'downloaded'      => 0,
            'last_updated'    => $release['published_at'],
            'sections'        => array(
                'description'  => 'Venue booking system for Needham Market Scout Group with Home Assistant integration.',
                'changelog'    => nl2br( esc_html( $release['body'] ?? 'No changelog provided.' ) ),
            ),
            'download_link'   => $this->get_download_url( $release ),
        );

        return $info;
    }

    /**
     * Add Authorization header to download requests for private repos.
     */
    public function add_auth_header( $args, $url ) {
        // Only add auth for GitHub API/download URLs
        if ( strpos( $url, 'github.com/' . $this->repo ) === false &&
             strpos( $url, 'api.github.com/repos/' . $this->repo ) === false &&
             strpos( $url, 'codeload.github.com/' . $this->repo ) === false ) {
            return $args;
        }

        $token = $this->get_token();
        if ( ! empty( $token ) ) {
            $args['headers']['Authorization'] = 'token ' . $token;
            $args['headers']['Accept']        = 'application/octet-stream';
        }

        return $args;
    }

    /**
     * Fix the extracted directory name after unzipping.
     *
     * GitHub's auto-generated zipball extracts to something like
     * "madboymatt26-mathlin-booking-abc1234/" — we need to rename it
     * to "mathlin-booking/" so WordPress recognises it as the same plugin.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        // Only act on our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $source;
        }

        global $wp_filesystem;

        $corrected_source = trailingslashit( $remote_source ) . $this->slug . '/';

        // If the source already has the right name, nothing to do
        if ( $source === $corrected_source ) {
            return $source;
        }

        // Check if the zip contains the plugin in a subdirectory (repo_subdir)
        // GitHub source zips contain the full repo, so the plugin is at
        // <extracted-dir>/wp-plugin/mathlin-booking/
        $extracted_dirs = $wp_filesystem->dirlist( $source );
        if ( $extracted_dirs ) {
            $first_dir = key( $extracted_dirs );
            $subdir_path = trailingslashit( $source ) . $first_dir . '/' . $this->repo_subdir . '/';

            if ( $wp_filesystem->is_dir( $subdir_path ) ) {
                // The plugin is nested inside the repo — move it up
                $wp_filesystem->move( $subdir_path, $corrected_source );
                // Clean up the extracted repo directory
                $wp_filesystem->delete( $source, true );
                return $corrected_source;
            }
        }

        // Otherwise just rename the top-level directory
        $wp_filesystem->move( $source, $corrected_source );
        return $corrected_source;
    }
}
