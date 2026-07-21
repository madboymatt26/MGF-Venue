<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Database {

    public static function create_tables() {
        global $wpdb;
        $lock = self::acquire_migration_lock();
        if ( is_wp_error( $lock ) ) return $lock;
        update_option( 'mbs_migration_state', array( 'status' => 'running', 'target' => MBS_DB_VERSION, 'started_at' => current_time( 'mysql' ) ), false );
        /** Exposes a post-lock lifecycle point for operational tooling and deterministic overlap tests. */
        if ( function_exists( 'do_action' ) ) do_action( 'mbs_migration_lock_acquired', $lock );
        try {

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
            legacy_billing_excluded TINYINT(1) NOT NULL DEFAULT 0,
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

        // First-class recurring-series records. Occurrence bookings retain
        // their legacy series_id column for compatibility; no foreign keys are
        // used because WordPress table prefixes and dbDelta do not manage them
        // reliably across all supported hosts.
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        // dbDelta splits schema statements on semicolons, including quoted
        // defaults. Inserts persist the full recurrence rule, so the schema
        // fallback must remain semicolon-free.
        $series_sql = "CREATE TABLE {$series_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            series_ref            VARCHAR(20)  NOT NULL,
            status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
            version               BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            contact_name          VARCHAR(100) NOT NULL,
            contact_organisation  VARCHAR(100) DEFAULT '',
            contact_email         VARCHAR(150) NOT NULL,
            contact_phone         VARCHAR(30)  DEFAULT '',
            contact_address       TEXT         DEFAULT '',
            space                 VARCHAR(60)  NOT NULL,
            kitchen               TINYINT(1)   NOT NULL DEFAULT 0,
            all_day               TINYINT(1)   NOT NULL DEFAULT 0,
            scout_use             TINYINT(1)   NOT NULL DEFAULT 0,
            pricing_tier          VARCHAR(30)  NOT NULL DEFAULT 'standard',
            start_time            TIME         DEFAULT NULL,
            end_time              TIME         DEFAULT NULL,
            attendees             SMALLINT     NOT NULL DEFAULT 1,
            purpose               VARCHAR(255) NOT NULL,
            notes                 TEXT         DEFAULT '',
            start_date            DATE         NOT NULL,
            repeat_until          DATE         NOT NULL,
            recurrence_rule       VARCHAR(100) NOT NULL DEFAULT 'FREQ=WEEKLY',
            schedule_json         LONGTEXT     DEFAULT NULL,
            price_per_booking     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            requested_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            accepted_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            conflict_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            blocked_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_count           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            exceptions_json       LONGTEXT     DEFAULT NULL,
            billing_mode          VARCHAR(30)  NOT NULL DEFAULT 'monthly',
            billing_treatment     VARCHAR(30)  NOT NULL DEFAULT 'manual_consolidated',
            deposit_policy        VARCHAR(30)  NOT NULL DEFAULT 'none',
            payment_method        VARCHAR(30)  NOT NULL DEFAULT 'online',
            automatic_reminders   TINYINT(1)   NOT NULL DEFAULT 1,
            invoice_lead_days     SMALLINT UNSIGNED NOT NULL DEFAULT 28,
            payment_terms_days    SMALLINT UNSIGNED NOT NULL DEFAULT 14,
            billing_schedule_json LONGTEXT     DEFAULT NULL,
            terms_hash            CHAR(64)     DEFAULT NULL,
            terms_accepted_at     DATETIME     DEFAULT NULL,
            confirmation_sent_at  DATETIME     DEFAULT NULL,
            metadata_incomplete   TINYINT(1)   NOT NULL DEFAULT 0,
            adopted_at            DATETIME     DEFAULT NULL,
            adopted_by            BIGINT(20) UNSIGNED DEFAULT NULL,
            adoption_state        VARCHAR(20)  NOT NULL DEFAULT 'not_required',
            adoption_version      VARCHAR(40)  DEFAULT NULL,
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY series_ref (series_ref),
            KEY idx_series_status (status),
            KEY idx_series_email (contact_email),
            KEY idx_series_dates (start_date, repeat_until),
            KEY idx_series_billing (billing_treatment, billing_mode)
        ) {$charset};";
        dbDelta( $series_sql );

        // Immutable consolidated invoice documents. All financial values use
        // integer minor units (pence for GBP); no floating-point values are
        // persisted in this domain.
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $invoice_sql = "CREATE TABLE {$invoice_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_ref           VARCHAR(30)  NOT NULL,
            document_type         VARCHAR(20)  NOT NULL DEFAULT 'invoice',
            parent_invoice_id     BIGINT(20) UNSIGNED DEFAULT NULL,
            series_ref            VARCHAR(20)  DEFAULT NULL,
            status                VARCHAR(20)  NOT NULL DEFAULT 'draft',
            version               BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            contact_name          VARCHAR(100) NOT NULL,
            contact_organisation  VARCHAR(100) DEFAULT '',
            contact_email         VARCHAR(150) NOT NULL,
            contact_address       TEXT         DEFAULT '',
            billing_mode          VARCHAR(30)  NOT NULL DEFAULT 'monthly',
            period_start          DATE         DEFAULT NULL,
            period_end            DATE         DEFAULT NULL,
            currency              CHAR(3)      NOT NULL DEFAULT 'GBP',
            subtotal_minor        BIGINT(20)   NOT NULL DEFAULT 0,
            tax_minor             BIGINT(20)   NOT NULL DEFAULT 0,
            total_minor           BIGINT(20)   NOT NULL DEFAULT 0,
            paid_minor            BIGINT(20)   NOT NULL DEFAULT 0,
            credited_minor        BIGINT(20)   NOT NULL DEFAULT 0,
            idempotency_key       VARCHAR(64)  NOT NULL,
            idempotency_request_hash CHAR(64)  DEFAULT NULL,
            payment_token_hash    CHAR(64)     DEFAULT NULL,
            payment_token_created_at DATETIME  DEFAULT NULL,
            issued_at             DATETIME     DEFAULT NULL,
            issued_email_sent_at  DATETIME     DEFAULT NULL,
            due_at                DATETIME     DEFAULT NULL,
            voided_at             DATETIME     DEFAULT NULL,
            void_reason           VARCHAR(255) DEFAULT '',
            reminder_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_reminded_at      DATETIME     DEFAULT NULL,
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY invoice_ref (invoice_ref),
            UNIQUE KEY invoice_idempotency (idempotency_key),
            KEY idx_invoice_series (series_ref),
            KEY idx_invoice_status_due (status, due_at),
            KEY idx_invoice_period (period_start, period_end),
            KEY idx_invoice_parent (parent_invoice_id)
        ) {$charset};";
        dbDelta( $invoice_sql );

        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $item_sql = "CREATE TABLE {$item_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_ref              VARCHAR(30) NOT NULL,
            invoice_id            BIGINT(20) UNSIGNED NOT NULL,
            item_type             VARCHAR(20) NOT NULL DEFAULT 'hire',
            booking_ref           VARCHAR(20) DEFAULT NULL,
            service_date          DATE        DEFAULT NULL,
            description           VARCHAR(255) NOT NULL,
            quantity_milli        BIGINT(20)  NOT NULL DEFAULT 1000,
            unit_amount_minor     BIGINT(20)  NOT NULL DEFAULT 0,
            line_total_minor      BIGINT(20)  NOT NULL DEFAULT 0,
            pricing_snapshot_json LONGTEXT    DEFAULT NULL,
            created_at            DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY item_ref (item_ref),
            KEY idx_item_invoice (invoice_id),
            KEY idx_item_booking (booking_ref),
            KEY idx_item_service_date (service_date)
        ) {$charset};";
        dbDelta( $item_sql );

        $transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $transaction_sql = "CREATE TABLE {$transaction_table} (
            id                      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_ref         VARCHAR(40) NOT NULL,
            invoice_id              BIGINT(20) UNSIGNED NOT NULL,
            provider                VARCHAR(30) NOT NULL,
            provider_transaction_id VARCHAR(100) DEFAULT NULL,
            transaction_type        VARCHAR(20) NOT NULL DEFAULT 'payment',
            status                  VARCHAR(20) NOT NULL DEFAULT 'pending',
            amount_minor            BIGINT(20) UNSIGNED NOT NULL,
            parent_transaction_id   BIGINT(20) UNSIGNED DEFAULT NULL,
            refunded_minor          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            currency                CHAR(3)     NOT NULL DEFAULT 'GBP',
            idempotency_key         VARCHAR(100) NOT NULL,
            idempotency_request_hash CHAR(64)    DEFAULT NULL,
            metadata_json           LONGTEXT    DEFAULT NULL,
            occurred_at             DATETIME    DEFAULT NULL,
            receipt_sent_at         DATETIME    DEFAULT NULL,
            created_at              DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY transaction_ref (transaction_ref),
            UNIQUE KEY transaction_idempotency (idempotency_key),
            UNIQUE KEY provider_transaction (provider, provider_transaction_id),
            KEY idx_transaction_invoice (invoice_id, status),
            KEY idx_transaction_occurred (occurred_at),
            KEY idx_transaction_parent (parent_transaction_id)
        ) {$charset};";
        dbDelta( $transaction_sql );

        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $allocation_sql = "CREATE TABLE {$allocation_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id            BIGINT(20) UNSIGNED NOT NULL,
            booking_ref           VARCHAR(20) NOT NULL,
            active_booking_ref    VARCHAR(20) DEFAULT NULL,
            allocated_minor       BIGINT(20)  NOT NULL DEFAULT 0,
            refunded_minor        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status                VARCHAR(20) NOT NULL DEFAULT 'active',
            released_at           DATETIME    DEFAULT NULL,
            created_at            DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY active_booking (active_booking_ref),
            KEY idx_allocation_invoice (invoice_id, status),
            KEY idx_allocation_booking (booking_ref)
        ) {$charset};";
        dbDelta( $allocation_sql );

        $reservation_table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;
        $reservation_sql = "CREATE TABLE {$reservation_table} (
            id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_ref    VARCHAR(40) NOT NULL,
            invoice_id         BIGINT(20) UNSIGNED NOT NULL,
            invoice_ref        VARCHAR(30) NOT NULL,
            order_id           BIGINT(20) UNSIGNED DEFAULT NULL,
            amount_minor       BIGINT(20) UNSIGNED NOT NULL,
	            status             VARCHAR(30) NOT NULL DEFAULT 'active',
	            version            BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
	            balance_version    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	            expires_at         DATETIME DEFAULT NULL,
            last_error         TEXT DEFAULT '',
            created_at         DATETIME NOT NULL,
            updated_at         DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY reservation_ref (reservation_ref),
            UNIQUE KEY invoice_owner (invoice_id),
            UNIQUE KEY order_owner (order_id),
            KEY idx_reservation_status (status, expires_at)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $reservation_sql );

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

        $engines = self::ensure_transactional_engines();
        $verified = is_wp_error( $engines ) ? $engines : self::verify_schema();
        if ( is_wp_error( $verified ) ) {
            update_option( 'mbs_migration_state', array(
                'status' => 'failed', 'target' => MBS_DB_VERSION, 'failed_at' => current_time( 'mysql' ),
                'message' => $verified->get_error_message(),
            ), false );
            add_action( 'admin_notices', array( __CLASS__, 'migration_health_notice' ) );
            return $verified;
        }
        update_option( 'mbs_db_version', MBS_DB_VERSION );
        update_option( 'mbs_migration_state', array( 'status' => 'complete', 'target' => MBS_DB_VERSION, 'completed_at' => current_time( 'mysql' ) ), false );
        return true;
        } finally {
            self::release_migration_lock( $lock );
        }
    }

    private static function acquire_migration_lock() {
        global $wpdb;
        $token = 'mbs_migration_' . substr( hash( 'sha256', ( defined('DB_NAME') ? DB_NAME : 'wordpress' ) . ':' . $wpdb->prefix ), 0, 32 );
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $token ) ) === 1 ) {
            update_option( 'mbs_migration_lock', array( 'owner' => $token, 'connection' => (int)$wpdb->get_var('SELECT CONNECTION_ID()'), 'acquired_at' => current_time('mysql') ), false );
            return $token;
        }
        return new WP_Error( 'migration_locked', 'Another MGF Venue database migration is already running.' );
    }

    private static function release_migration_lock( $token ) {
        global $wpdb;
        // Remove the diagnostic while the advisory lock is still held. A
        // successor cannot acquire/set its own diagnostic until RELEASE_LOCK.
        delete_option( 'mbs_migration_lock' );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $token ) );
    }

    private static function ensure_transactional_engines() {
        global $wpdb;
        $tables = array( $wpdb->prefix.MBS_TABLE, $wpdb->prefix.MBS_SERIES_TABLE, $wpdb->prefix.MBS_INVOICE_TABLE, $wpdb->prefix.MBS_INVOICE_ITEM_TABLE, $wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE, $wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE, $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE );
        foreach($tables as $table){$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table));if($row&&!empty($row->Engine)&&strtolower($row->Engine)!=='innodb'){if($wpdb->query("ALTER TABLE `{$table}` ENGINE=InnoDB")===false)return new WP_Error('transactional_engine_required',"{$table} is not transactional and could not be converted to InnoDB. Restore a backup, correct database permissions/capacity, and retry.");}}
        return true;
    }

    private static function verify_schema() {
        global $wpdb;
        $requirements = array(
            $wpdb->prefix . MBS_TABLE => array(
                'columns' => array( 'id','ref','status','name','organisation','email','phone','address','space','kitchen','booking_date','booking_date_end','all_day','scout_use','pricing_tier','start_time','end_time','attendees','purpose','notes','amount','deposit_paid','amount_paid','invoice_number','ha_notified','reminder_sent','access_sent','feedback_sent','chase_count','last_chased','series_id','legacy_billing_excluded','admin_notes','custom_fields','modification_token','is_public','user_id','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','idx_date','idx_status','idx_ref','idx_series','idx_email','idx_chase' ),
            ),
            $wpdb->prefix . MBS_SERIES_TABLE => array(
                'columns' => array( 'id','series_ref','status','version','contact_name','contact_organisation','contact_email','contact_phone','contact_address','space','kitchen','all_day','scout_use','pricing_tier','start_time','end_time','attendees','purpose','notes','start_date','repeat_until','recurrence_rule','schedule_json','price_per_booking','estimated_total','requested_count','accepted_count','conflict_count','blocked_count','error_count','exceptions_json','billing_mode','billing_treatment','deposit_policy','payment_method','automatic_reminders','invoice_lead_days','payment_terms_days','billing_schedule_json','terms_hash','terms_accepted_at','confirmation_sent_at','metadata_incomplete','adopted_at','adopted_by','adoption_state','adoption_version','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','series_ref','idx_series_status','idx_series_email','idx_series_dates','idx_series_billing' ),
            ),
            $wpdb->prefix . MBS_INVOICE_TABLE => array(
                'columns' => array( 'id','invoice_ref','document_type','parent_invoice_id','series_ref','status','version','contact_name','contact_organisation','contact_email','contact_address','billing_mode','period_start','period_end','currency','subtotal_minor','tax_minor','total_minor','paid_minor','credited_minor','idempotency_key','idempotency_request_hash','payment_token_hash','payment_token_created_at','issued_at','issued_email_sent_at','due_at','voided_at','void_reason','reminder_count','last_reminded_at','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','invoice_ref','invoice_idempotency','idx_invoice_series','idx_invoice_status_due','idx_invoice_period','idx_invoice_parent' ),
            ),
            $wpdb->prefix . MBS_INVOICE_ITEM_TABLE => array(
                'columns' => array( 'id','item_ref','invoice_id','item_type','booking_ref','service_date','description','quantity_milli','unit_amount_minor','line_total_minor','pricing_snapshot_json','created_at' ),
                'indexes' => array( 'PRIMARY','item_ref','idx_item_invoice','idx_item_booking','idx_item_service_date' ),
            ),
            $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE => array(
                'columns' => array( 'id','transaction_ref','invoice_id','provider','provider_transaction_id','transaction_type','status','amount_minor','parent_transaction_id','refunded_minor','currency','idempotency_key','idempotency_request_hash','metadata_json','occurred_at','receipt_sent_at','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','transaction_ref','transaction_idempotency','provider_transaction','idx_transaction_invoice','idx_transaction_occurred','idx_transaction_parent' ),
            ),
            $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE => array(
                'columns' => array( 'id','invoice_id','booking_ref','active_booking_ref','allocated_minor','refunded_minor','status','released_at','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','active_booking','idx_allocation_invoice','idx_allocation_booking' ),
            ),
            $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE => array(
	                'columns' => array( 'id','reservation_ref','invoice_id','invoice_ref','order_id','amount_minor','status','version','balance_version','expires_at','last_error','created_at','updated_at' ),
                'indexes' => array( 'PRIMARY','reservation_ref','invoice_owner','order_owner','idx_reservation_status' ),
            ),
            $wpdb->prefix . 'mathlin_blocked_dates' => array( 'columns' => array( 'id','date_from','date_to','space','reason','created_at' ), 'indexes' => array( 'PRIMARY','idx_dates' ) ),
            $wpdb->prefix . 'mathlin_audit_log' => array( 'columns' => array( 'id','ref','action','details','user_id','user_name','ip_address','created_at' ), 'indexes' => array( 'PRIMARY','idx_ref','idx_action','idx_date' ) ),
            $wpdb->prefix . 'mathlin_email_queue' => array( 'columns' => array( 'id','to_email','subject','body','headers','attachments','attempts','status','next_retry','created_at' ), 'indexes' => array( 'PRIMARY','idx_status' ) ),
            $wpdb->prefix . 'mathlin_mod_requests' => array( 'columns' => array( 'id','booking_ref','request_type','status','requested_data','notes','admin_response','resolved_at','resolved_by','created_at' ), 'indexes' => array( 'PRIMARY','idx_ref','idx_status' ) ),
        );
	        $missing = array();
	        $index_semantics = array(
	            $wpdb->prefix.MBS_TABLE => array('PRIMARY'=>array(true,array('id')),'idx_date'=>array(false,array('booking_date')),'idx_status'=>array(false,array('status')),'idx_ref'=>array(false,array('ref')),'idx_series'=>array(false,array('series_id')),'idx_email'=>array(false,array('email')),'idx_chase'=>array(false,array('status','created_at','chase_count'))),
	            $wpdb->prefix.MBS_SERIES_TABLE => array('PRIMARY'=>array(true,array('id')),'series_ref'=>array(true,array('series_ref')),'idx_series_status'=>array(false,array('status')),'idx_series_email'=>array(false,array('contact_email')),'idx_series_dates'=>array(false,array('start_date','repeat_until')),'idx_series_billing'=>array(false,array('billing_treatment','billing_mode'))),
	            $wpdb->prefix.MBS_INVOICE_TABLE => array('PRIMARY'=>array(true,array('id')),'invoice_ref'=>array(true,array('invoice_ref')),'invoice_idempotency'=>array(true,array('idempotency_key')),'idx_invoice_series'=>array(false,array('series_ref')),'idx_invoice_status_due'=>array(false,array('status','due_at')),'idx_invoice_period'=>array(false,array('period_start','period_end')),'idx_invoice_parent'=>array(false,array('parent_invoice_id'))),
	            $wpdb->prefix.MBS_INVOICE_ITEM_TABLE => array('PRIMARY'=>array(true,array('id')),'item_ref'=>array(true,array('item_ref')),'idx_item_invoice'=>array(false,array('invoice_id')),'idx_item_booking'=>array(false,array('booking_ref')),'idx_item_service_date'=>array(false,array('service_date'))),
	            $wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE => array('PRIMARY'=>array(true,array('id')),'transaction_ref'=>array(true,array('transaction_ref')),'transaction_idempotency'=>array(true,array('idempotency_key')),'provider_transaction'=>array(true,array('provider','provider_transaction_id')),'idx_transaction_invoice'=>array(false,array('invoice_id','status')),'idx_transaction_occurred'=>array(false,array('occurred_at')),'idx_transaction_parent'=>array(false,array('parent_transaction_id'))),
	            $wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE => array('PRIMARY'=>array(true,array('id')),'active_booking'=>array(true,array('active_booking_ref')),'idx_allocation_invoice'=>array(false,array('invoice_id','status')),'idx_allocation_booking'=>array(false,array('booking_ref'))),
	            $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE => array('PRIMARY'=>array(true,array('id')),'reservation_ref'=>array(true,array('reservation_ref')),'invoice_owner'=>array(true,array('invoice_id')),'order_owner'=>array(true,array('order_id')),'idx_reservation_status'=>array(false,array('status','expires_at'))),
	            $wpdb->prefix.'mathlin_blocked_dates' => array('PRIMARY'=>array(true,array('id')),'idx_dates'=>array(false,array('date_from','date_to'))),
	            $wpdb->prefix.'mathlin_audit_log' => array('PRIMARY'=>array(true,array('id')),'idx_ref'=>array(false,array('ref')),'idx_action'=>array(false,array('action')),'idx_date'=>array(false,array('created_at'))),
	            $wpdb->prefix.'mathlin_email_queue' => array('PRIMARY'=>array(true,array('id')),'idx_status'=>array(false,array('status','next_retry'))),
	            $wpdb->prefix.'mathlin_mod_requests' => array('PRIMARY'=>array(true,array('id')),'idx_ref'=>array(false,array('booking_ref')),'idx_status'=>array(false,array('status'))),
	        );
	        $column_semantics = array(
	            $wpdb->prefix.MBS_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'legacy_billing_excluded'=>array('tinyint(1)','NO','0','')),
	            $wpdb->prefix.MBS_SERIES_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'version'=>array('bigint(20) unsigned','NO','1','')),
	            $wpdb->prefix.MBS_INVOICE_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'version'=>array('bigint(20) unsigned','NO','1',''),'total_minor'=>array('bigint(20)','NO','0',''),'paid_minor'=>array('bigint(20)','NO','0',''),'credited_minor'=>array('bigint(20)','NO','0','')),
	            $wpdb->prefix.MBS_INVOICE_ITEM_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'invoice_id'=>array('bigint(20) unsigned','NO',null,''),'line_total_minor'=>array('bigint(20)','NO','0','')),
	            $wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'invoice_id'=>array('bigint(20) unsigned','NO',null,''),'amount_minor'=>array('bigint(20) unsigned','NO',null,''),'refunded_minor'=>array('bigint(20) unsigned','NO','0','')),
	            $wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'invoice_id'=>array('bigint(20) unsigned','NO',null,''),'allocated_minor'=>array('bigint(20)','NO','0',''),'refunded_minor'=>array('bigint(20) unsigned','NO','0','')),
	            $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE => array('id'=>array('bigint(20) unsigned','NO',null,'auto_increment'),'invoice_id'=>array('bigint(20) unsigned','NO',null,''),'order_id'=>array('bigint(20) unsigned','YES',null,''),'amount_minor'=>array('bigint(20) unsigned','NO',null,''),'version'=>array('bigint(20) unsigned','NO','1',''),'balance_version'=>array('bigint(20) unsigned','NO','0','')),
	        );
	        foreach ( $requirements as $table => $required ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { $missing[] = 'table ' . $table; continue; }
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
            foreach ( $required['columns'] as $column ) if ( ! in_array( $column, $columns, true ) ) $missing[] = $table . '.' . $column;
	            $full_columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`" );
	            $columns_by_name = array();
	            foreach((array)$full_columns as $column)if(isset($column->Field))$columns_by_name[$column->Field]=$column;
	            if(isset($columns_by_name['id'])){
	                $id=$columns_by_name['id'];
	                if(strtolower((string)$id->Type)!=='bigint(20) unsigned'||(string)$id->Null!=='NO'||strtolower((string)$id->Extra)!=='auto_increment')$missing[]=$table.'.id definition';
	            }
	            if(!empty($wpdb->collate)){
	                $table_status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table));
	                if($table_status&&strtolower((string)$table_status->Collation)!==strtolower((string)$wpdb->collate))$missing[]=$table.' collation';
	            }
	            foreach(($column_semantics[$table]??array()) as $name=>$expected){
	                if(!isset($columns_by_name[$name]))continue;
	                $actual=$columns_by_name[$name];
	                $actual_default=$actual->Default===null?null:(string)$actual->Default;
	                if(strtolower((string)$actual->Type)!==$expected[0]||(string)$actual->Null!==$expected[1]||$actual_default!==$expected[2]||strtolower((string)$actual->Extra)!==$expected[3])$missing[]=$table.'.'.$name.' definition';
	            }
	            $index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );
	            $indexes = array_values( array_unique( array_map( static function ( $row ) { return (string) $row->Key_name; }, (array) $index_rows ) ) );
	            foreach ( $required['indexes'] as $index ) if ( ! in_array( $index, $indexes, true ) ) $missing[] = $table . ' index ' . $index;
	            $semantic_index_rows=$index_rows&&isset($index_rows[0]->Non_unique)&&isset($index_rows[0]->Column_name);
	            foreach(($index_semantics[$table]??array()) as $name=>$expected){
	                if(!$semantic_index_rows)continue;
	                $rows=array_values(array_filter((array)$index_rows,static function($row)use($name){return(string)$row->Key_name===$name;}));
	                usort($rows,static function($a,$b){return(int)$a->Seq_in_index<=>(int)$b->Seq_in_index;});
	                $unique=$rows&&((int)$rows[0]->Non_unique===0);
	                $index_columns=array_map(static function($row){return(string)$row->Column_name;},$rows);
	                if(!$rows||$unique!==$expected[0]||$index_columns!==$expected[1])$missing[]=$table.' index '.$name.' definition';
	            }
	        }
        return $missing ? new WP_Error( 'migration_verification_failed', 'Database migration is incomplete: ' . implode( ', ', $missing ) ) : true;
    }

    public static function migration_health_notice() {
        $state = get_option( 'mbs_migration_state', array() );
        if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'failed' ) return;
        echo '<div class="notice notice-error"><p><strong>MGF Venue database upgrade failed.</strong> ' . esc_html( $state['message'] ?? 'Required schema objects are missing.' ) . ' The version marker was not advanced; correct the database problem and retry activation.</p></div>';
    }

    /**
     * Run database migrations for existing installs.
     */
	    private static function maybe_run_migrations() {
        global $wpdb;
	        $table = $wpdb->prefix . MBS_TABLE;

	        // Schema 7 introduced this safety boundary. Add it explicitly because
	        // dbDelta does not reliably alter CREATE TABLE IF NOT EXISTS shapes.
	        $legacy_column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'legacy_billing_excluded'" );
	        if ( ! $legacy_column ) {
	            if ( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN legacy_billing_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER series_id" ) === false ) return new WP_Error( 'legacy_billing_column_failed', 'Could not add the legacy billing exclusion column.' );
	        }
	        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
	        $backfilled = $wpdb->query(
	            "UPDATE {$table} b INNER JOIN {$series_table} s ON s.series_ref=b.series_id
	             SET b.legacy_billing_excluded=1
	             WHERE b.legacy_billing_excluded=0 AND (b.status IN ('paid','deposit_paid','cancelled','archived') OR b.amount_paid>0 OR b.deposit_paid>0)"
	        );
	        if ( $backfilled === false ) return new WP_Error( 'legacy_billing_backfill_failed', 'Could not preserve historical recurring billing during upgrade.' );
	        $unsafe = (int)$wpdb->get_var(
	            "SELECT COUNT(*) FROM {$table} b INNER JOIN {$series_table} s ON s.series_ref=b.series_id
	             WHERE b.legacy_billing_excluded=0 AND (b.status IN ('paid','deposit_paid','cancelled','archived') OR b.amount_paid>0 OR b.deposit_paid>0)"
	        );
	        if ( $unsafe !== 0 ) return new WP_Error( 'legacy_billing_backfill_incomplete', 'Historical recurring billing exclusions remain incomplete.' );

	        $reservation_table=$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE;
	        if(!$wpdb->get_row("SHOW COLUMNS FROM {$reservation_table} WHERE Field='balance_version'")){
	            if($wpdb->query("ALTER TABLE {$reservation_table} ADD COLUMN balance_version BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER version")===false)return new WP_Error('reservation_generation_column_failed','Could not add reservation balance generations.');
	        }
	        $owner_rows=$wpdb->get_results("SHOW INDEX FROM {$reservation_table} WHERE Key_name='invoice_owner' ORDER BY Seq_in_index");
	        $owner_semantics=$owner_rows&&isset($owner_rows[0]->Non_unique)&&isset($owner_rows[0]->Column_name);
	        $owner_exact=!$owner_semantics||(count($owner_rows)===1&&(int)$owner_rows[0]->Non_unique===0&&(string)$owner_rows[0]->Column_name==='invoice_id');
	        if(!$owner_exact){
	            if($owner_rows&&$wpdb->query("ALTER TABLE {$reservation_table} DROP INDEX invoice_owner")===false)return new WP_Error('reservation_owner_index_drop_failed','Could not replace malformed invoice ownership index.');
	            if($wpdb->query("ALTER TABLE {$reservation_table} ADD UNIQUE KEY invoice_owner (invoice_id)")===false)return new WP_Error('reservation_owner_index_failed','Could not enforce unique invoice ownership.');
	        }

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
        }
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_series'" );
        if ( empty( $indexes ) ) $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_series (series_id)" );

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

        // v3.17.2: Rename the WooCommerce payment product to the generic name.
        // Only runs once (guarded by a flag) so it doesn't overwrite any future
        // admin customisation of the product name.
        if ( ! get_option( 'mbs_woo_product_renamed', false ) ) {
            $product_id = (int) get_option( 'mbs_woo_product_id', 0 );
            if ( $product_id && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $product_id );
                if ( $product ) {
                    $product->set_name( 'Venue Booking Payment' );
                    $product->set_description( 'Payment for venue booking.' );
                    $product->save();
                }
            }
            update_option( 'mbs_woo_product_renamed', true );
        }
    }

    public static function on_deactivate() {
        // Data is preserved on deactivation. Use uninstall.php to fully remove.
    }
}
