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
            scout_use       TINYINT(1)   NOT NULL DEFAULT 0,
            start_time      TIME         DEFAULT NULL,
            end_time        TIME         DEFAULT NULL,
            attendees       SMALLINT     NOT NULL DEFAULT 1,
            purpose         VARCHAR(255) NOT NULL,
            notes           TEXT         DEFAULT '',
            amount          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            invoice_number  VARCHAR(30)  DEFAULT '',
            ha_notified     TINYINT(1)   NOT NULL DEFAULT 0,
            reminder_sent   TINYINT(1)   NOT NULL DEFAULT 0,
            feedback_sent   TINYINT(1)   NOT NULL DEFAULT 0,
            chase_count     SMALLINT     NOT NULL DEFAULT 0,
            last_chased     DATETIME     DEFAULT NULL,
            series_id       VARCHAR(20)  DEFAULT NULL,
            admin_notes     TEXT         DEFAULT '',
            custom_fields   TEXT         DEFAULT '',
            modification_token VARCHAR(64) DEFAULT NULL,
            is_public       TINYINT(1)   NOT NULL DEFAULT 0,
            user_id         BIGINT(20)   DEFAULT NULL,
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

        // Email queue table
        $queue_table = $wpdb->prefix . 'mathlin_email_queue';
        $sql4 = "CREATE TABLE IF NOT EXISTS {$queue_table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            to_email    VARCHAR(150) NOT NULL,
            subject     VARCHAR(255) NOT NULL,
            body        LONGTEXT     NOT NULL,
            headers     TEXT         DEFAULT '',
            attachments TEXT         DEFAULT '',
            attempts    SMALLINT     NOT NULL DEFAULT 0,
            status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
            next_retry  DATETIME     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status, next_retry)
        ) {$charset};";
        dbDelta( $sql4 );

        // Modification requests table
        $mod_table = $wpdb->prefix . 'mathlin_mod_requests';
        $sql5 = "CREATE TABLE IF NOT EXISTS {$mod_table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref     VARCHAR(20)  NOT NULL,
            request_type    VARCHAR(20)  NOT NULL DEFAULT 'modify',
            status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
            requested_data  TEXT         DEFAULT '',
            notes           TEXT         DEFAULT '',
            admin_response  TEXT         DEFAULT '',
            resolved_at     DATETIME     DEFAULT NULL,
            resolved_by     BIGINT(20)   DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ref    (booking_ref),
            KEY idx_status (status)
        ) {$charset};";
        dbDelta( $sql5 );

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

        // Add custom_fields column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'custom_fields'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN custom_fields TEXT DEFAULT '' AFTER admin_notes" );
        }

        // Add modification_token column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'modification_token'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN modification_token VARCHAR(64) DEFAULT NULL AFTER custom_fields" );
        }

        // Add is_public column if missing (public vs private events)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_public'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER modification_token" );
        }

        // Add scout_use column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'scout_use'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN scout_use TINYINT(1) NOT NULL DEFAULT 0 AFTER all_day" );
        }

        // Add user_id column if missing (hirer portal)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'user_id'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN user_id BIGINT(20) DEFAULT NULL AFTER is_public" );
        }

        // SEC-FIX-007: Add index on email column for hirer portal performance
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_email'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_email (email)" );
        }

        // SEC-FIX-009: Add composite index for payment chaser query performance
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_chase'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_chase (status, created_at, chase_count)" );
        }

        // v3.0.0: Add deposit_paid column to track deposit amount paid
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'deposit_paid'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN deposit_paid DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER amount" );
        }

        // v3.2.0: Add amount_paid column to track total payments received
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'amount_paid'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN amount_paid DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER deposit_paid" );
            // Migrate existing data: if status is 'paid', amount_paid = amount
            // If status is 'deposit_paid', amount_paid = deposit_paid
            $wpdb->query( "UPDATE {$table} SET amount_paid = amount WHERE status = 'paid'" );
            $wpdb->query( "UPDATE {$table} SET amount_paid = deposit_paid WHERE status = 'deposit_paid' AND deposit_paid > 0" );
        }

        // v3.0.0: Add pricing_tier column to track which tier was applied
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'pricing_tier'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN pricing_tier VARCHAR(30) NOT NULL DEFAULT 'standard' AFTER scout_use" );
        }

        // v3.0.8: Migrate booking_confirmed email template to remove hardcoded "14 days" text
        $saved_tpl = get_option( 'mbs_email_template_booking_confirmed', array() );
        if ( ! empty( $saved_tpl['body'] ) && strpos( $saved_tpl['body'], 'Payment is due within 14 days' ) !== false ) {
            $new_body = "Hi {name},\n\nGreat news — your booking has been confirmed!\n\nInvoice Number: {invoice}\n\nPlease see the payment schedule below and the attached invoice for full details.\n\nIf you have any questions, please contact us at {admin_email}.";
            update_option( 'mbs_email_template_booking_confirmed', array(
                'subject' => $saved_tpl['subject'],
                'body'    => $new_body,
            ) );
        }

        // v3.2.9: Fix empty string booking_date_end values — set to booking_date
        $wpdb->query( "UPDATE {$table} SET booking_date_end = booking_date WHERE booking_date_end = '' OR booking_date_end = '0000-00-00'" );

        // v3.4.0: Add access_sent column
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'access_sent'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN access_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent" );
        }

        // v3.13.0: Add feedback_sent column (post-booking feedback & review module)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'feedback_sent'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN feedback_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent" );
        }
    }

    public static function on_deactivate() {
        // Data is preserved on deactivation. Use uninstall.php to fully remove.
    }
}
