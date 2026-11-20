<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-payment-expiry.php';
require_once __DIR__ . '/class-credit-accounts.php';

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
            space           VARCHAR(255) NOT NULL,
            kitchen         TINYINT(1)   NOT NULL DEFAULT 0,
            booking_date    DATE         NOT NULL,
            booking_date_end DATE         DEFAULT NULL,
            all_day         TINYINT(1)   NOT NULL DEFAULT 0,
            scout_use       TINYINT(1)   NOT NULL DEFAULT 0,
            pricing_tier    VARCHAR(30)  NOT NULL DEFAULT 'standard',
            start_time      TIME         DEFAULT NULL,
            end_time        TIME         DEFAULT NULL,
            attendees       SMALLINT     NOT NULL DEFAULT 1,
            purpose         VARCHAR(255) NOT NULL,
            notes           TEXT         DEFAULT '',
            amount          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            deposit_paid    DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            amount_paid     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            invoice_number  VARCHAR(30)  DEFAULT '',
            ha_notified     TINYINT(1)   NOT NULL DEFAULT 0,
            reminder_sent   TINYINT(1)   NOT NULL DEFAULT 0,
            access_sent     TINYINT(1)   NOT NULL DEFAULT 0,
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
            current_invoice_document_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date   (booking_date),
            KEY idx_status (status),
            KEY idx_series (series_id),
            KEY idx_email  (email),
            KEY idx_chase  (status, created_at, chase_count),
            KEY idx_status_scout_date (status, scout_use, booking_date),
            KEY idx_date_end (booking_date_end, booking_date),
            KEY idx_series_date (series_id, booking_date),
            KEY idx_user_id (user_id)
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
            space                 VARCHAR(255) NOT NULL,
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
            billing_reviewed_at   DATETIME     DEFAULT NULL,
            billing_reviewed_by   BIGINT(20) UNSIGNED DEFAULT NULL,
            billing_reviewed_version BIGINT(20) UNSIGNED DEFAULT NULL,
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
            KEY idx_invoice_parent (parent_invoice_id),
            KEY idx_invoice_email (contact_email),
            KEY idx_invoice_issued (document_type, status, issued_at)
        ) {$charset};";
        dbDelta( $invoice_sql );

        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $item_sql = "CREATE TABLE {$item_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_ref              VARCHAR(30) NOT NULL,
            invoice_id            BIGINT(20) UNSIGNED NOT NULL,
            item_type             VARCHAR(20) NOT NULL DEFAULT 'hire',
            booking_ref           VARCHAR(20) DEFAULT NULL,
            related_ref           VARCHAR(30) DEFAULT NULL,
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
            KEY idx_item_related_ref (related_ref),
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

        // Durable finance-event outbox. Completed payment/refund rows are
        // inserted here in the same transaction as the billing ledger. Card
        // events wait for their WooPayments payout rather than being posted to
        // OSM individually (a payout can also contain shop sales and fees).
        $osm_outbox_table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        $osm_outbox_sql = "CREATE TABLE {$osm_outbox_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_ref             VARCHAR(100) NOT NULL,
            transaction_id        BIGINT(20) UNSIGNED DEFAULT NULL,
            transaction_ref       VARCHAR(40) NOT NULL DEFAULT '',
            event_type            VARCHAR(20) NOT NULL DEFAULT 'legacy_refund',
            provider              VARCHAR(30) NOT NULL DEFAULT '',
            provider_transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            payment_gateway       VARCHAR(60) NOT NULL DEFAULT '',
            target_mode           VARCHAR(30) NOT NULL DEFAULT 'manual_review',
            payout_id             VARCHAR(100) NOT NULL DEFAULT '',
            booking_ref           VARCHAR(20) NOT NULL DEFAULT '',
            invoice_ref           VARCHAR(30) NOT NULL,
            order_id              BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            refund_id             BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            amount_minor          BIGINT(20) UNSIGNED NOT NULL,
            currency              CHAR(3) NOT NULL DEFAULT 'GBP',
            occurred_at           DATETIME DEFAULT NULL,
            section_id            BIGINT(20) UNSIGNED DEFAULT NULL,
            bank_account_id       BIGINT(20) UNSIGNED DEFAULT NULL,
            category_id           BIGINT(20) UNSIGNED DEFAULT NULL,
            item_id               BIGINT(20) UNSIGNED DEFAULT NULL,
            reversal_kind         VARCHAR(20) NOT NULL DEFAULT '',
            payload_json          LONGTEXT NOT NULL,
            payload_hash          CHAR(64) NOT NULL DEFAULT '',
            status                VARCHAR(30) NOT NULL DEFAULT 'manual_review',
            attempts              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at       DATETIME DEFAULT NULL,
            claimed_at            DATETIME DEFAULT NULL,
            lease_expires_at      DATETIME DEFAULT NULL,
            last_error            TEXT DEFAULT '',
            response_code         SMALLINT UNSIGNED DEFAULT NULL,
            bank_transaction_id   BIGINT(20) UNSIGNED DEFAULT NULL,
            remote_transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            delivered_at          DATETIME DEFAULT NULL,
            resolved_at           DATETIME DEFAULT NULL,
            resolved_by           BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_ref (event_ref),
            UNIQUE KEY transaction_owner (transaction_id),
            KEY idx_osm_delivery (status, next_attempt_at),
            KEY idx_osm_payout (payout_id, status),
            KEY idx_osm_order (order_id),
            KEY idx_osm_booking (booking_ref),
            KEY idx_osm_refund (refund_id),
            KEY idx_lease (status, lease_expires_at)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $osm_outbox_sql );

        // Exactly one row owns each WooPayments payout and each matched OSM
        // bank transaction. The unique keys are the last line of defence
        // against duplicate cashbook records during retries or overlapping
        // administrator/MCP requests.
        $osm_payout_table = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        $osm_payout_sql = "CREATE TABLE {$osm_payout_table} (
            id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payout_ref            VARCHAR(100) NOT NULL,
            payout_date           DATE NOT NULL,
            currency              CHAR(3) NOT NULL DEFAULT 'GBP',
            amount_minor          BIGINT(20) NOT NULL,
            bank_account_id       BIGINT(20) UNSIGNED NOT NULL,
            bank_transaction_id   BIGINT(20) UNSIGNED DEFAULT NULL,
            cashbook_transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
            payload_json          LONGTEXT NOT NULL,
            payload_hash          CHAR(64) NOT NULL,
            status                VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            claimed_at            DATETIME DEFAULT NULL,
            lease_expires_at      DATETIME DEFAULT NULL,
            last_error            TEXT DEFAULT '',
            response_code         SMALLINT UNSIGNED DEFAULT NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            delivered_at          DATETIME DEFAULT NULL,
            resolved_at           DATETIME DEFAULT NULL,
            resolved_by           BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY payout_ref (payout_ref),
            UNIQUE KEY bank_transaction_owner (bank_transaction_id),
            KEY idx_payout_status (status, payout_date),
            KEY idx_payout_cashbook (cashbook_transaction_id),
            KEY idx_lease (status, lease_expires_at)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $osm_payout_sql );

        // Immutable invoice document snapshots (append-only history)
        $documents_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $documents_sql = "CREATE TABLE {$documents_table} (
            id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id             BIGINT(20) UNSIGNED DEFAULT NULL,
            invoice_id             BIGINT(20) UNSIGNED DEFAULT NULL,
            booking_ref            VARCHAR(20) DEFAULT NULL,
            invoice_number         VARCHAR(30) NOT NULL,
            revision               SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            document_type          VARCHAR(20) NOT NULL DEFAULT 'invoice',
            snapshot_json          LONGTEXT NOT NULL,
            snapshot_version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            logo_asset_id          BIGINT(20) UNSIGNED DEFAULT NULL,
            logo_content_hash      CHAR(64) DEFAULT NULL,
            status                 VARCHAR(20) NOT NULL DEFAULT 'issued',
            supersedes_document_id BIGINT(20) UNSIGNED DEFAULT NULL,
            issued_at              DATETIME NOT NULL,
            created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_revision (booking_id, revision),
            UNIQUE KEY invoice_revision (invoice_id, revision),
            KEY idx_booking_ref (booking_ref),
            KEY idx_doc_status (status),
            KEY idx_supersedes (supersedes_document_id)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $documents_sql );

        // Content-addressed immutable logo/document assets
        $assets_table = $wpdb->prefix . MBS_DOCUMENT_ASSETS_TABLE;
        $assets_sql = "CREATE TABLE {$assets_table} (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            content_hash   CHAR(64) NOT NULL,
            mime_type      VARCHAR(30) NOT NULL,
            width          SMALLINT UNSIGNED NOT NULL,
            height         SMALLINT UNSIGNED NOT NULL,
            file_size      INT UNSIGNED NOT NULL,
            content        LONGBLOB NOT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY asset_hash (content_hash)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $assets_sql );

        // Opaque guest download tokens for invoice documents
        $tokens_table = $wpdb->prefix . MBS_DOWNLOAD_TOKENS_TABLE;
        $tokens_sql = "CREATE TABLE {$tokens_table} (
            id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash       CHAR(64) NOT NULL,
            document_id      BIGINT(20) UNSIGNED NOT NULL,
            expires_at       DATETIME NOT NULL,
            max_uses         SMALLINT UNSIGNED DEFAULT NULL,
            use_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            revoked_at       DATETIME DEFAULT NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY idx_document (document_id),
            KEY idx_expiry (expires_at)
        ) ENGINE=InnoDB {$charset};";
        dbDelta( $tokens_sql );

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
            KEY idx_date   (created_at),
            KEY idx_ref_created (ref, created_at)
        ) {$charset};";
        dbDelta( $sql3 );

        // Email queue table (transactional outbox)
        $queue_table = $wpdb->prefix . 'mathlin_email_queue';
        $sql4 = "CREATE TABLE IF NOT EXISTS {$queue_table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            to_email        VARCHAR(150) NOT NULL,
            subject         VARCHAR(255) NOT NULL,
            body            LONGTEXT     NOT NULL,
            headers         TEXT         DEFAULT '',
            attachments     TEXT         DEFAULT '',
            attempts        SMALLINT     NOT NULL DEFAULT 0,
            status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
            next_retry      DATETIME     DEFAULT NULL,
            message_key     VARCHAR(100) DEFAULT NULL,
            payload_hash    CHAR(64)     DEFAULT NULL,
            attachment_meta LONGTEXT     DEFAULT NULL,
            claimed_at      DATETIME     DEFAULT NULL,
            lease_expires_at DATETIME    DEFAULT NULL,
            worker_id       VARCHAR(50)  DEFAULT NULL,
            accepted_at     DATETIME     DEFAULT NULL,
            last_error      TEXT         DEFAULT '',
            failure_category VARCHAR(30) DEFAULT NULL,
            last_error_code VARCHAR(80)  DEFAULT NULL,
            last_error_details TEXT      DEFAULT '',
            last_attempt_at DATETIME     DEFAULT NULL,
            failed_at       DATETIME     DEFAULT NULL,
            resent_from_id  BIGINT(20) UNSIGNED DEFAULT NULL,
            resent_by       BIGINT(20) UNSIGNED DEFAULT NULL,
            message_type    VARCHAR(50)  DEFAULT NULL,
            reference_type  VARCHAR(50)  DEFAULT NULL,
            reference_id    BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY message_key (message_key),
            KEY idx_status (status, next_retry),
            KEY idx_queue_reference (reference_type, reference_id, id),
            KEY idx_queue_failed (status, failure_category, failed_at),
            KEY idx_queue_lease (status, lease_expires_at),
            KEY idx_queue_status_created (status, created_at)
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
            KEY idx_status (status),
            KEY idx_ref_status (booking_ref, status),
            KEY idx_status_created (status, created_at)
        ) {$charset};";
        dbDelta( $sql5 );

        // Run migrations for existing installs AFTER all canonical tables
        // exist. Fresh installs get the complete schema from dbDelta above;
        // migrations handle ALTER/backfill for existing installations only.
        $migrated = self::maybe_run_migrations();
        if ( $migrated !== true ) return self::fail_migration( $migrated, 'Database migration operations failed.' );

        $expiry_sql = MBS_Payment_Expiry::schema();
        dbDelta( $expiry_sql );
        $schema_sql = array(
            $wpdb->prefix . 'mathlin_payment_expiry' => $expiry_sql,
            $table => $sql,
            $series_table => $series_sql,
            $invoice_table => $invoice_sql,
            $item_table => $item_sql,
            $transaction_table => $transaction_sql,
            $allocation_table => $allocation_sql,
            $reservation_table => $reservation_sql,
            $osm_outbox_table => $osm_outbox_sql,
            $osm_payout_table => $osm_payout_sql,
            $documents_table => $documents_sql,
            $assets_table => $assets_sql,
            $tokens_table => $tokens_sql,
            $blocked_table => $sql2,
            $audit_table => $sql3,
            $queue_table => $sql4,
            $mod_table => $sql5,
        );
        foreach ( MBS_Credit_Accounts::schemas() as $credit_table => $credit_sql ) {
            dbDelta( $credit_sql );
            $schema_sql[$credit_table] = $credit_sql;
        }
        $engines = self::ensure_transactional_engines();
        $verified = is_wp_error( $engines ) ? $engines : self::verify_schema( $schema_sql );
        if ( ! is_wp_error( $verified ) ) $verified = self::verify_legacy_financial_backfill();
        if ( is_wp_error( $verified ) ) {
            return self::fail_migration( $verified, 'Database post-migration verification failed.' );
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
        $tables = array(
            $wpdb->prefix.MBS_TABLE, $wpdb->prefix.MBS_SERIES_TABLE, $wpdb->prefix.MBS_INVOICE_TABLE,
            $wpdb->prefix.MBS_INVOICE_ITEM_TABLE, $wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE,
            $wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE, $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE,
            $wpdb->prefix.MBS_OSM_OUTBOX_TABLE, $wpdb->prefix.MBS_OSM_PAYOUT_TABLE,
            $wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE,
            $wpdb->prefix.MBS_DOCUMENT_ASSETS_TABLE, $wpdb->prefix.MBS_DOWNLOAD_TOKENS_TABLE,
            $wpdb->prefix.'mathlin_blocked_dates',
            $wpdb->prefix.'mathlin_audit_log', $wpdb->prefix.'mathlin_email_queue',
            $wpdb->prefix.'mathlin_mod_requests', $wpdb->prefix.'mathlin_payment_expiry',
            $wpdb->prefix.'mathlin_credit_accounts', $wpdb->prefix.'mathlin_credit_assignments',
        );
        foreach($tables as $table){$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table));if($row&&!empty($row->Engine)&&strtolower($row->Engine)!=='innodb'){if($wpdb->query("ALTER TABLE `{$table}` ENGINE=InnoDB")===false)return new WP_Error('transactional_engine_required',"{$table} is not transactional and could not be converted to InnoDB. Restore a backup, correct database permissions/capacity, and retry.");}}
        return true;
    }

    private static function fail_migration( $error, $fallback_message ) {
        if ( ! is_wp_error( $error ) ) $error = new WP_Error( 'migration_operation_failed', $fallback_message );
        update_option( 'mbs_migration_state', array(
            'status' => 'failed',
            'target' => MBS_DB_VERSION,
            'failed_at' => current_time( 'mysql' ),
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ), false );
        add_action( 'admin_notices', array( __CLASS__, 'migration_health_notice' ) );
        return $error;
    }

    /**
     * Compare every expected column and index against a clean table created
     * from the canonical SQL on the same MariaDB connection. This avoids a
     * hand-maintained name-only allow-list and includes lengths, precision,
     * signedness, null/default/auto-increment, collation, key roles, index
     * uniqueness/order/prefix lengths, and primary keys.
     */
    private static function verify_schema( $schema_sql ) {
        global $wpdb;
        $problems = array();
        foreach ( $schema_sql as $table => $sql ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                $problems[] = 'missing table ' . $table;
                continue;
            }
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
            if ( ! $status || strtolower( (string) $status->Engine ) !== 'innodb' ) $problems[] = $table . ' engine';
            if ( ! empty( $wpdb->collate ) && $status && strtolower( (string) $status->Collation ) !== strtolower( (string) $wpdb->collate ) ) {
                $problems[] = $table . ' table collation';
            }

            $temporary = $wpdb->prefix . 'mbs_expect_' . substr( hash( 'sha256', $table ), 0, 12 );
            $pattern = '/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?' . preg_quote( $table, '/' ) . '`?/i';
            $expected_sql = preg_replace( $pattern, 'CREATE TEMPORARY TABLE `' . $temporary . '`', trim( $sql ), 1, $replaced );
            if ( $replaced !== 1 || $wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . $temporary . '`' ) === false || $wpdb->query( $expected_sql ) === false ) {
                $problems[] = $table . ' expected-schema manifest could not be materialised';
                continue;
            }

            $expected_columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $temporary . '`' );
            $actual_columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`' );
            $actual_by_name = array();
            foreach ( (array) $actual_columns as $column ) $actual_by_name[(string)$column->Field] = $column;
            foreach ( (array) $expected_columns as $expected ) {
                $name = (string) $expected->Field;
                if ( ! isset( $actual_by_name[$name] ) ) { $problems[] = $table . '.' . $name . ' missing'; continue; }
                $actual = $actual_by_name[$name];
                foreach ( array( 'Type','Collation','Null','Key','Default','Extra' ) as $property ) {
                    $expected_value = $expected->$property === null ? null : strtolower( (string) $expected->$property );
                    $actual_value = $actual->$property === null ? null : strtolower( (string) $actual->$property );
                    if ( $expected_value !== $actual_value ) {
                        $problems[] = $table . '.' . $name . ' ' . strtolower( $property );
                    }
                }
            }

            $expected_indexes = self::normalise_indexes( $wpdb->get_results( 'SHOW INDEX FROM `' . $temporary . '`' ) );
            $actual_indexes = self::normalise_indexes( $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`' ) );
            foreach ( $expected_indexes as $name => $expected_index ) {
                if ( ! isset( $actual_indexes[$name] ) || $actual_indexes[$name] !== $expected_index ) {
                    $problems[] = $table . ' index ' . $name;
                }
            }
            $wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . $temporary . '`' );
        }
        return $problems ? new WP_Error( 'migration_verification_failed', 'Database migration is incomplete: ' . implode( ', ', array_unique( $problems ) ) ) : true;
    }

    private static function normalise_indexes( $rows ) {
        $indexes = array();
        foreach ( (array) $rows as $row ) {
            $name = (string) $row->Key_name;
            $sequence = (int) $row->Seq_in_index;
            $indexes[$name][$sequence] = array(
                'non_unique' => (int) $row->Non_unique,
                'column' => (string) $row->Column_name,
                'prefix' => $row->Sub_part === null ? null : (int) $row->Sub_part,
                'index_type' => strtolower( (string) $row->Index_type ),
            );
        }
        foreach ( $indexes as &$index ) ksort( $index );
        unset( $index );
        ksort( $indexes );
        return $indexes;
    }

    public static function migration_health_notice() {
        $state = get_option( 'mbs_migration_state', array() );
        if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'failed' ) return;
        echo '<div class="notice notice-error"><p><strong>MGF Venue database upgrade failed.</strong> ' . esc_html( $state['message'] ?? 'Required schema objects are missing.' ) . ' The version marker was not advanced; correct the database problem and retry activation.</p></div>';
    }

    public static function migration_is_current() {
        $state = get_option( 'mbs_migration_state', array() );
        return get_option( 'mbs_db_version' ) === MBS_DB_VERSION
            && is_array( $state )
            && ( $state['status'] ?? '' ) === 'complete'
            && ( $state['target'] ?? '' ) === MBS_DB_VERSION;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /** Build the one canonical historical-finance predicate used by migration and adoption. */
    private static function historical_financial_predicate( $booking_alias ) {
        global $wpdb;
        $clauses = array(
            "{$booking_alias}.status IN ('paid','deposit_paid','credited','refunded','cancelled','archived')",
            "{$booking_alias}.amount_paid > 0",
            "{$booking_alias}.deposit_paid > 0",
        );
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        if ( self::table_exists( $item_table ) ) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$item_table} hi WHERE hi.booking_ref={$booking_alias}.ref)";
        }
        if ( self::table_exists( $allocation_table ) ) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$allocation_table} ha WHERE ha.booking_ref={$booking_alias}.ref)";
        }
        if ( self::table_exists( $transaction_table ) && self::table_exists( $item_table ) ) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$transaction_table} ht INNER JOIN {$item_table} hti ON hti.invoice_id=ht.invoice_id WHERE hti.booking_ref={$booking_alias}.ref)";
        }
        if ( self::table_exists( $invoice_table ) && self::table_exists( $item_table ) ) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$invoice_table} hc INNER JOIN {$item_table} hci ON (hci.invoice_id=hc.id OR hci.invoice_id=hc.parent_invoice_id) WHERE hc.document_type='credit_note' AND hci.booking_ref={$booking_alias}.ref)";
        }
        return '(' . implode( ' OR ', $clauses ) . ')';
    }

    public static function backfill_legacy_financial_history( $series_ref = '' ) {
        global $wpdb;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( ! self::table_exists( $booking_table ) || ! self::table_exists( $series_table ) ) {
            return new WP_Error( 'legacy_billing_tables_missing', 'Legacy financial history cannot be verified because its source tables are missing.' );
        }
        $predicate = self::historical_financial_predicate( 'b' );
        $series_where = $series_ref !== '' ? $wpdb->prepare( ' AND b.series_id=%s', $series_ref ) : '';
        $query = "UPDATE {$booking_table} b INNER JOIN {$series_table} s ON s.series_ref=b.series_id
                  SET b.legacy_billing_excluded=1
                  WHERE b.legacy_billing_excluded=0 AND {$predicate}{$series_where}";
        if ( $wpdb->query( $query ) === false ) {
            return new WP_Error( 'legacy_billing_backfill_failed', 'Could not preserve historical recurring billing during upgrade: ' . $wpdb->last_error );
        }
        return self::verify_legacy_financial_backfill( $series_ref );
    }

    public static function verify_legacy_financial_backfill( $series_ref = '' ) {
        global $wpdb;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( ! self::table_exists( $booking_table ) || ! self::table_exists( $series_table ) ) {
            return new WP_Error( 'legacy_billing_tables_missing', 'Legacy financial history cannot be verified because its source tables are missing.' );
        }
        $predicate = self::historical_financial_predicate( 'b' );
        $series_where = $series_ref !== '' ? $wpdb->prepare( ' AND b.series_id=%s', $series_ref ) : '';
        $query = "SELECT COUNT(*) FROM {$booking_table} b INNER JOIN {$series_table} s ON s.series_ref=b.series_id
                  WHERE b.legacy_billing_excluded=0 AND {$predicate}{$series_where}";
        $unsafe = $wpdb->get_var( $query );
        if ( $unsafe === null ) {
            return new WP_Error( 'legacy_billing_backfill_verification_failed', 'Could not verify historical recurring billing exclusions' . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) );
        }
        return (int) $unsafe === 0 ? true : new WP_Error( 'legacy_billing_backfill_incomplete', 'Historical recurring billing exclusions remain incomplete.' );
    }

    private static function migration_query( $query, $step ) {
        global $wpdb;
        return $wpdb->query( $query ) === false
            ? new WP_Error( 'migration_query_failed', $step . ' failed: ' . $wpdb->last_error )
            : true;
    }

    /**
     * Run database migrations for existing installs.
     */
	    private static function maybe_run_migrations() {
        global $wpdb;
	        $table = $wpdb->prefix . MBS_TABLE;

            // dbDelta does not reliably alter CREATE TABLE IF NOT EXISTS tables.
            // Widen existing installations explicitly; never narrow an unexpected column.
            foreach ( array( $table, $wpdb->prefix . MBS_SERIES_TABLE ) as $space_table ) {
                $space_column = $wpdb->get_row( "SHOW COLUMNS FROM {$space_table} WHERE Field = 'space'" );
                $space_type = strtolower( (string) ( $space_column->Type ?? '' ) );
                if ( $space_type === 'varchar(255)' ) continue;
                if ( ! preg_match( '/^varchar\((\d+)\)$/D', $space_type, $space_match ) || (int) $space_match[1] > 255 ) return new WP_Error( 'space_column_upgrade_failed', 'Cannot safely widen ' . $space_table . '.space from ' . $space_type . '.' );
                $space_upgrade = self::migration_query( "ALTER TABLE {$space_table} MODIFY COLUMN space VARCHAR(255) NOT NULL", 'Multi-area space width upgrade' );
                if ( is_wp_error( $space_upgrade ) ) return $space_upgrade;
            }

	        // Schema 7 introduced this safety boundary. Add it explicitly because
	        // dbDelta does not reliably alter CREATE TABLE IF NOT EXISTS shapes.
	        $legacy_column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'legacy_billing_excluded'" );
	        if ( ! $legacy_column ) {
	            if ( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN legacy_billing_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER series_id" ) === false ) return new WP_Error( 'legacy_billing_column_failed', 'Could not add the legacy billing exclusion column.' );
	        }
	        $reservation_table=$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE;
	        if(!$wpdb->get_row("SHOW COLUMNS FROM {$reservation_table} WHERE Field='balance_version'")){
	            if($wpdb->query("ALTER TABLE {$reservation_table} ADD COLUMN balance_version BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER version")===false)return new WP_Error('reservation_generation_column_failed','Could not add reservation balance generations.');
	        }
	        $owner_rows=$wpdb->get_results("SHOW INDEX FROM {$reservation_table} WHERE Key_name='invoice_owner'");
	        $owner_semantics=$owner_rows&&isset($owner_rows[0]->Non_unique)&&isset($owner_rows[0]->Column_name);
	        $owner_exact=!$owner_semantics||(count($owner_rows)===1&&(int)$owner_rows[0]->Non_unique===0&&(string)$owner_rows[0]->Column_name==='invoice_id');
	        if(!$owner_exact){
	            if($owner_rows&&$wpdb->query("ALTER TABLE {$reservation_table} DROP INDEX invoice_owner")===false)return new WP_Error('reservation_owner_index_drop_failed','Could not replace malformed invoice ownership index.');
	            if($wpdb->query("ALTER TABLE {$reservation_table} ADD UNIQUE KEY invoice_owner (invoice_id)")===false)return new WP_Error('reservation_owner_index_failed','Could not enforce unique invoice ownership.');
	        }

        // Migrate ENUM status column to VARCHAR if needed
        $col_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'status'" );
        if ( $col_info && strpos( strtolower( $col_info->Type ), 'enum' ) !== false ) {
            $result=self::migration_query("ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'",'Booking status schema correction');if(is_wp_error($result))return $result;
        }

        // Fix any bookings with empty status (from failed ENUM writes)
        $result=self::migration_query("UPDATE {$table} SET status = 'pending' WHERE status = '' OR status IS NULL",'Empty booking status repair');if(is_wp_error($result))return $result;

        // Add booking_date_end column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'booking_date_end'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN booking_date_end DATE DEFAULT NULL AFTER booking_date",'booking_date_end addition');if(is_wp_error($result))return $result;
        }

        // Add all_day column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'all_day'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0 AFTER booking_date_end",'all_day addition');if(is_wp_error($result))return $result;
        }

        // Add recurring columns if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'series_id'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN series_id VARCHAR(20) DEFAULT NULL AFTER ha_notified",'series_id addition');if(is_wp_error($result))return $result;
        }
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_series'" );
        if ( empty( $indexes ) ) {$result=self::migration_query("ALTER TABLE {$table} ADD KEY idx_series (series_id)",'series index addition');if(is_wp_error($result))return $result;}

        // Add admin_notes column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'admin_notes'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN admin_notes TEXT DEFAULT '' AFTER notes",'admin_notes addition');if(is_wp_error($result))return $result;
        }

        // Add reminder_sent column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'reminder_sent'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN reminder_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER ha_notified",'reminder_sent addition');if(is_wp_error($result))return $result;
        }

        // Add payment chase columns if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'chase_count'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN chase_count SMALLINT NOT NULL DEFAULT 0 AFTER reminder_sent",'chase_count addition');if(is_wp_error($result))return $result;
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN last_chased DATETIME DEFAULT NULL AFTER chase_count",'last_chased addition');if(is_wp_error($result))return $result;
        }

        // Add custom_fields column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'custom_fields'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN custom_fields TEXT DEFAULT '' AFTER admin_notes",'custom_fields addition');if(is_wp_error($result))return $result;
        }

        // Add modification_token column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'modification_token'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN modification_token VARCHAR(64) DEFAULT NULL AFTER custom_fields",'modification_token addition');if(is_wp_error($result))return $result;
        }

        // Add is_public column if missing (public vs private events)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_public'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER modification_token",'is_public addition');if(is_wp_error($result))return $result;
        }

        // Add scout_use column if missing
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'scout_use'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN scout_use TINYINT(1) NOT NULL DEFAULT 0 AFTER all_day",'scout_use addition');if(is_wp_error($result))return $result;
        }

        // Add user_id column if missing (hirer portal)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'user_id'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN user_id BIGINT(20) DEFAULT NULL AFTER is_public",'user_id addition');if(is_wp_error($result))return $result;
        }

        // SEC-FIX-007: Add index on email column for hirer portal performance
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_email'" );
        if ( empty( $indexes ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD KEY idx_email (email)",'email index addition');if(is_wp_error($result))return $result;
        }

        // SEC-FIX-009: Add composite index for payment chaser query performance
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_chase'" );
        if ( empty( $indexes ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD KEY idx_chase (status, created_at, chase_count)",'chase index addition');if(is_wp_error($result))return $result;
        }

        // v3.0.0: Add deposit_paid column to track deposit amount paid
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'deposit_paid'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN deposit_paid DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER amount",'deposit_paid addition');if(is_wp_error($result))return $result;
        }

        // v3.2.0: Add amount_paid column to track total payments received
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'amount_paid'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN amount_paid DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER deposit_paid",'amount_paid addition');if(is_wp_error($result))return $result;
            // Migrate existing data: if status is 'paid', amount_paid = amount
            // If status is 'deposit_paid', amount_paid = deposit_paid
            $result=self::migration_query("UPDATE {$table} SET amount_paid = amount WHERE status = 'paid'",'paid amount backfill');if(is_wp_error($result))return $result;
            $result=self::migration_query("UPDATE {$table} SET amount_paid = deposit_paid WHERE status = 'deposit_paid' AND deposit_paid > 0",'deposit amount backfill');if(is_wp_error($result))return $result;
        }

        // v3.0.0: Add pricing_tier column to track which tier was applied
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'pricing_tier'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN pricing_tier VARCHAR(30) NOT NULL DEFAULT 'standard' AFTER scout_use",'pricing_tier addition');if(is_wp_error($result))return $result;
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
        $result=self::migration_query("UPDATE {$table} SET booking_date_end = booking_date WHERE booking_date_end = '' OR booking_date_end = '0000-00-00'",'booking date range repair');if(is_wp_error($result))return $result;

        // v3.4.0: Add access_sent column
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'access_sent'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN access_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent",'access_sent addition');if(is_wp_error($result))return $result;
        }

        // v3.13.0: Add feedback_sent column (post-booking feedback & review module)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'feedback_sent'" );
        if ( empty( $col ) ) {
            $result=self::migration_query("ALTER TABLE {$table} ADD COLUMN feedback_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent",'feedback_sent addition');if(is_wp_error($result))return $result;
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

        // v3.22.0: Add current_invoice_document_id to bookings
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'current_invoice_document_id'" );
        if ( empty( $col ) ) {
            $result = self::migration_query( "ALTER TABLE {$table} ADD COLUMN current_invoice_document_id BIGINT(20) UNSIGNED DEFAULT NULL", 'current_invoice_document_id addition' );
            if ( is_wp_error( $result ) ) return $result;
        }

        // v3.22.0: Add billing review fields to series
        $series_table_name = $wpdb->prefix . MBS_SERIES_TABLE;
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$series_table_name} LIKE 'billing_reviewed_at'" );
        if ( empty( $col ) ) {
            $result = self::migration_query( "ALTER TABLE {$series_table_name} ADD COLUMN billing_reviewed_at DATETIME DEFAULT NULL", 'billing_reviewed_at addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$series_table_name} ADD COLUMN billing_reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER billing_reviewed_at", 'billing_reviewed_by addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$series_table_name} ADD COLUMN billing_reviewed_version BIGINT(20) UNSIGNED DEFAULT NULL AFTER billing_reviewed_by", 'billing_reviewed_version addition' );
            if ( is_wp_error( $result ) ) return $result;
        }

        // v3.22.0: Extend email queue for transactional outbox
        $queue_table_name = $wpdb->prefix . 'mathlin_email_queue';
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$queue_table_name} LIKE 'message_key'" );
        if ( empty( $col ) ) {
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN message_key VARCHAR(100) DEFAULT NULL", 'queue message_key addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN payload_hash CHAR(64) DEFAULT NULL AFTER message_key", 'queue payload_hash addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN attachment_meta LONGTEXT DEFAULT NULL AFTER payload_hash", 'queue attachment_meta addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN claimed_at DATETIME DEFAULT NULL AFTER attachment_meta", 'queue claimed_at addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN lease_expires_at DATETIME DEFAULT NULL AFTER claimed_at", 'queue lease_expires_at addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN worker_id VARCHAR(50) DEFAULT NULL AFTER lease_expires_at", 'queue worker_id addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN accepted_at DATETIME DEFAULT NULL AFTER worker_id", 'queue accepted_at addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN last_error TEXT DEFAULT '' AFTER accepted_at", 'queue last_error addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN message_type VARCHAR(50) DEFAULT NULL AFTER last_error", 'queue message_type addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN reference_type VARCHAR(50) DEFAULT NULL AFTER message_type", 'queue reference_type addition' );
            if ( is_wp_error( $result ) ) return $result;
            $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN reference_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER reference_type", 'queue reference_id addition' );
            if ( is_wp_error( $result ) ) return $result;
            $indexes = $wpdb->get_results( "SHOW INDEX FROM {$queue_table_name} WHERE Key_name='message_key'" );
            if ( empty( $indexes ) ) {
                $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD UNIQUE KEY message_key (message_key)", 'queue message_key index' );
                if ( is_wp_error( $result ) ) return $result;
            }
        }

        // v3.48.0-schema-16: Drop exact duplicate indexes if present on upgraded installs.
        $dup_indexes = array(
            array( $table, 'idx_ref', 'drop duplicate bookings idx_ref' ),
            array( $queue_table_name, 'idx_queue_processing', 'drop duplicate queue idx_queue_processing' ),
        );
        foreach ( $dup_indexes as $dup ) {
            $existing_idx = $wpdb->get_results( "SHOW INDEX FROM {$dup[0]} WHERE Key_name='{$dup[1]}'" );
            if ( ! empty( $existing_idx ) ) {
                $result = self::migration_query( "ALTER TABLE {$dup[0]} DROP INDEX {$dup[1]}", $dup[2] );
                if ( is_wp_error( $result ) ) return $result;
            }
        }

        // v3.48.0-schema-16: Email queue diagnostic & retry columns.
        $queue_diag_cols = array(
            'failure_category'   => "VARCHAR(30) DEFAULT NULL AFTER last_error",
            'last_error_code'    => "VARCHAR(80) DEFAULT NULL AFTER failure_category",
            'last_error_details' => "TEXT DEFAULT '' AFTER last_error_code",
            'last_attempt_at'    => "DATETIME DEFAULT NULL AFTER last_error_details",
            'failed_at'          => "DATETIME DEFAULT NULL AFTER last_attempt_at",
            'resent_from_id'     => "BIGINT(20) UNSIGNED DEFAULT NULL AFTER failed_at",
            'resent_by'          => "BIGINT(20) UNSIGNED DEFAULT NULL AFTER resent_from_id",
        );
        foreach ( $queue_diag_cols as $col_name => $col_def ) {
            $col = $wpdb->get_results( "SHOW COLUMNS FROM {$queue_table_name} LIKE '{$col_name}'" );
            if ( empty( $col ) ) {
                $result = self::migration_query( "ALTER TABLE {$queue_table_name} ADD COLUMN {$col_name} {$col_def}", "queue {$col_name} addition" );
                if ( is_wp_error( $result ) ) return $result;
            }
        }

        // v3.48.0-schema-16: Indexed related_ref column on mathlin_invoice_items + backfill from pricing_snapshot_json.
        $item_table_name = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$item_table_name} LIKE 'related_ref'" );
        if ( empty( $col ) ) {
            $result = self::migration_query( "ALTER TABLE {$item_table_name} ADD COLUMN related_ref VARCHAR(30) DEFAULT NULL AFTER booking_ref", 'invoice_items related_ref addition' );
            if ( is_wp_error( $result ) ) return $result;
        }
        $unbackfilled_items = $wpdb->get_results( "SELECT id, pricing_snapshot_json FROM {$item_table_name} WHERE related_ref IS NULL AND pricing_snapshot_json LIKE '%\"related_ref\"%'" );
        if ( is_array( $unbackfilled_items ) ) {
            foreach ( $unbackfilled_items as $item_row ) {
                if ( ! isset( $item_row->id, $item_row->pricing_snapshot_json ) ) continue;
                $decoded = json_decode( (string) $item_row->pricing_snapshot_json, true );
                if ( is_array( $decoded ) && ! empty( $decoded['related_ref'] ) ) {
                    $ref_val = sanitize_text_field( (string) $decoded['related_ref'] );
                    if ( $ref_val !== '' ) {
                        $wpdb->update( $item_table_name, array( 'related_ref' => substr( $ref_val, 0, 30 ) ), array( 'id' => (int) $item_row->id ) );
                    }
                }
            }
        }

        // v3.48.0-schema-16: Hot-path indexes across bookings, invoices, items, audit, queue, mod_requests, and OSM tables.
        $invoice_table_name    = $wpdb->prefix . MBS_INVOICE_TABLE;
        $audit_table_name      = $wpdb->prefix . 'mathlin_audit_log';
        $mod_table_name        = $wpdb->prefix . 'mathlin_mod_requests';
        $osm_outbox_table_name = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        $osm_payout_table_name = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        $new_indexes = array(
            array( $table, 'idx_status_scout_date', '(status, scout_use, booking_date)' ),
            array( $table, 'idx_date_end', '(booking_date_end, booking_date)' ),
            array( $table, 'idx_series_date', '(series_id, booking_date)' ),
            array( $table, 'idx_user_id', '(user_id)' ),
            array( $invoice_table_name, 'idx_invoice_email', '(contact_email)' ),
            array( $invoice_table_name, 'idx_invoice_issued', '(document_type, status, issued_at)' ),
            array( $item_table_name, 'idx_item_related_ref', '(related_ref)' ),
            array( $audit_table_name, 'idx_ref_created', '(ref, created_at)' ),
            array( $queue_table_name, 'idx_queue_reference', '(reference_type, reference_id, id)' ),
            array( $queue_table_name, 'idx_queue_failed', '(status, failure_category, failed_at)' ),
            array( $queue_table_name, 'idx_queue_lease', '(status, lease_expires_at)' ),
            array( $queue_table_name, 'idx_queue_status_created', '(status, created_at)' ),
            array( $mod_table_name, 'idx_ref_status', '(booking_ref, status)' ),
            array( $mod_table_name, 'idx_status_created', '(status, created_at)' ),
            array( $osm_outbox_table_name, 'idx_lease', '(status, lease_expires_at)' ),
            array( $osm_payout_table_name, 'idx_lease', '(status, lease_expires_at)' ),
        );
        foreach ( $new_indexes as $idx ) {
            $existing_idx = $wpdb->get_results( "SHOW INDEX FROM {$idx[0]} WHERE Key_name='{$idx[1]}'" );
            if ( empty( $existing_idx ) ) {
                $result = self::migration_query( "ALTER TABLE {$idx[0]} ADD KEY {$idx[1]} {$idx[2]}", "index {$idx[1]} on {$idx[0]}" );
                if ( is_wp_error( $result ) ) return $result;
            }
        }

	        // Run only after every historical payment column above is present.
	        $backfilled = self::backfill_legacy_financial_history();
	        if ( is_wp_error( $backfilled ) ) return $backfilled;
	        return true;
	    }

    public static function on_deactivate() {
        // Data is preserved on deactivation. Use uninstall.php to fully remove.
    }
}
