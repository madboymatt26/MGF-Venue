<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Database {

    public static function create_tables() {
        global $wpdb;

        $table   = $wpdb->prefix . MBS_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ref             VARCHAR(20)  NOT NULL UNIQUE,
            status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
            name            VARCHAR(100) NOT NULL,
            organisation    VARCHAR(100) DEFAULT '',
            email           VARCHAR(150) NOT NULL,
            phone           VARCHAR(30)  NOT NULL,
            address         TEXT         NOT NULL,
            space           VARCHAR(60)  NOT NULL,
            kitchen         TINYINT(1)   NOT NULL DEFAULT 0,
            booking_date    DATE         NOT NULL,
            start_time      TIME         DEFAULT NULL,
            end_time        TIME         DEFAULT NULL,
            attendees       SMALLINT     NOT NULL DEFAULT 1,
            purpose         VARCHAR(255) NOT NULL,
            notes           TEXT         DEFAULT '',
            amount          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            invoice_number  VARCHAR(30)  DEFAULT '',
            ha_notified     TINYINT(1)   NOT NULL DEFAULT 0,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date   (booking_date),
            KEY idx_status (status),
            KEY idx_ref    (ref)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Migrate ENUM to VARCHAR if needed (for existing installs)
        self::maybe_migrate_status_column();

        update_option( 'mbs_db_version', MBS_VERSION );
    }

    /**
     * Migrate the status column from ENUM to VARCHAR if it's still an ENUM.
     * This allows new statuses (paid, archived) to be stored.
     */
    private static function maybe_migrate_status_column() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Check if the column is still ENUM
        $col_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'status'" );
        if ( $col_info && strpos( strtolower( $col_info->Type ), 'enum' ) !== false ) {
            $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'" );
        }

        // Fix any bookings with empty status (from failed ENUM writes)
        $wpdb->query( "UPDATE {$table} SET status = 'pending' WHERE status = '' OR status IS NULL" );
    }

    public static function on_deactivate() {
        // Data is preserved on deactivation. Use uninstall.php to fully remove.
    }
}
