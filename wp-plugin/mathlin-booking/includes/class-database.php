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
            booking_date_end DATE         DEFAULT NULL,
            all_day         TINYINT(1)   NOT NULL DEFAULT 0,
            start_time      TIME         DEFAULT NULL,
            end_time        TIME         DEFAULT NULL,
            attendees       SMALLINT     NOT NULL DEFAULT 1,
            purpose         VARCHAR(255) NOT NULL,
            notes           TEXT         DEFAULT '',
            amount          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            invoice_number  VARCHAR(30)  DEFAULT '',
            ha_notified     TINYINT(1)   NOT NULL DEFAULT 0,
            reminder_sent   TINYINT(1)   NOT NULL DEFAULT 0,
            chase_count     SMALLINT     NOT NULL DEFAULT 0,
            last_chased     DATETIME     DEFAULT NULL,
            series_id       VARCHAR(20)  DEFAULT NULL,
            admin_notes     TEXT         DEFAULT '',
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date   (booking_date),
            KEY idx_status (status),
            KEY idx_ref    (ref)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Blocked dates table
        $blocked_table = $wpdb->prefix . 'mathlin_blocked_dates';
        $sql2 = "CREATE TABLE IF NOT EXISTS {$blocked_table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            date_from   DATE         NOT NULL,
            date_to     DATE         NOT NULL,
            space       VARCHAR(60)  DEFAULT '',
            reason      VARCHAR(255) DEFAULT '',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_dates (date_from, date_to)
        ) {$charset};";
        dbDelta( $sql2 );

        // Run migrations for existing installs
        self::maybe_run_migrations();

        // Audit log table
        $audit_table = $wpdb->prefix . 'mathlin_audit_log';
        $sql3 = "CREATE TABLE IF NOT EXISTS {$audit_table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ref         VARCHAR(20)  NOT NULL,
            action      VARCHAR(30)  NOT NULL,
            details     TEXT         DEFAULT '',
            user_id     BIGINT(20)   NOT NULL DEFAULT 0,
            user_name   VARCHAR(100) DEFAULT '',
            ip_address  VARCHAR(45)  DEFAULT '',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ref    (ref),
            KEY idx_action (action),
            KEY idx_date   (created_at)
        ) {$charset};";
        dbDelta( $sql3 );

        update_option( 'mbs_db_version', MBS_VERSION );
    }

    /**
     * Run database migrations for existing installs.
     */
    private static function maybe_run_migrations() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Migrate ENUM status column to VARCHAR if needed
        $col_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'status'" );
        if ( $col_info && strpos( strtolower( $col_info->Type ), 'enum' ) !== false ) {
            $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'" );
        }

        // Fix any bookings with empty status (from failed ENUM writes)
        $wpdb->query( "UPDATE {$table} SET status = 'pending' WHERE status = '' OR status IS NULL" );

        // Add booking_date_end column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'booking_date_end'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN booking_date_end DATE DEFAULT NULL AFTER booking_date" );
        }

        // Add all_day column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'all_day'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0 AFTER booking_date_end" );
        }

        // Add recurring columns if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'series_id'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN series_id VARCHAR(20) DEFAULT NULL AFTER ha_notified" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_series (series_id)" );
        }

        // Add admin_notes column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'admin_notes'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN admin_notes TEXT DEFAULT '' AFTER notes" );
        }

        // Add reminder_sent column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'reminder_sent'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN reminder_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER ha_notified" );
        }

        // Add payment chase columns if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'chase_count'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN chase_count SMALLINT NOT NULL DEFAULT 0 AFTER reminder_sent" );
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN last_chased DATETIME DEFAULT NULL AFTER chase_count" );
        }
    }

    public static function on_deactivate() {
        // Data is preserved on deactivation. Use uninstall.php to fully remove.
    }
}
