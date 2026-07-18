<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Schedule preview and idempotent consolidated invoice generation. */
class MBS_Billing_Engine {

    const CRON_HOOK = 'mbs_daily_series_billing';

    public function init() {
        add_action( self::CRON_HOOK, array( $this, 'run_daily' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 06:00:00' ), 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function run_daily() {
        $result = self::catch_up( wp_date( 'Y-m-d' ) );
        if ( is_wp_error( $result ) ) {
            error_log( '[MGF Venue] Consolidated billing catch-up failed: ' . $result->get_error_message() );
        }
    }

    /** Configure a first-class series with optimistic concurrency. */
    public static function configure_series( $series_ref, $configuration, $expected_version ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        $mode = sanitize_key( $configuration['billing_mode'] ?? '' );
        $treatment = sanitize_key( $configuration['billing_treatment'] ?? '' );
        if ( ! in_array( $mode, array( 'monthly', 'termly', 'legacy_per_occurrence', 'upfront', 'none' ), true ) ) {
            return new WP_Error( 'invalid_billing_mode', 'Invalid billing mode.' );
        }
        if ( ! in_array( $treatment, array( 'manual_consolidated', 'invoice_managed', 'legacy_per_occurrence', 'none' ), true ) ) {
            return new WP_Error( 'invalid_billing_treatment', 'Invalid billing treatment.' );
        }
        $lead_days = max( 0, min( 365, (int) ( $configuration['invoice_lead_days'] ?? 28 ) ) );
        $terms_days = max( 0, min( 365, (int) ( $configuration['payment_terms_days'] ?? 14 ) ) );
        $schedule = $configuration['billing_schedule'] ?? array();
        if ( ! is_array( $schedule ) ) return new WP_Error( 'invalid_billing_schedule', 'Billing schedule must be structured data.' );

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET billing_mode = %s, billing_treatment = %s, invoice_lead_days = %d,
                 payment_terms_days = %d, billing_schedule_json = %s,
                 version = version + 1, updated_at = %s
             WHERE series_ref = %s AND version = %d",
            $mode, $treatment, $lead_days, $terms_days, wp_json_encode( $schedule ),
            current_time( 'mysql' ), sanitize_text_field( $series_ref ), (int) $expected_version
        ) );
        if ( $updated !== 1 ) return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded.' );
        return MBS_Series::get( $series_ref );
    }

    /** Preview periods and exact minor-unit totals without writing invoices. */
    public static function preview( $series_ref, $overrides = array() ) {
        $series = MBS_Series::get( $series_ref );
        if ( ! $series ) return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        if ( $overrides ) {
            $series = (object) array_merge( (array) $series, $overrides );
        }
        $bookings = self::billable_occurrences( $series_ref );
        $periods = self::build_periods( $series, $bookings );
        if ( is_wp_error( $periods ) ) return $periods;
        return array(
            'series_ref'        => $series->series_ref,
            'billing_mode'      => $series->billing_mode,
            'billing_treatment' => $series->billing_treatment,
            'currency'          => 'GBP',
            'periods'           => $periods,
        );
    }

    /** Build deterministic billing periods; exposed for dependency-free tests. */
    public static function build_periods( $series, $bookings ) {
        $mode = $series->billing_mode ?: 'monthly';
        if ( $mode === 'none' ) return array();
        if ( ! in_array( $mode, array( 'monthly', 'termly', 'legacy_per_occurrence', 'upfront' ), true ) ) {
            return new WP_Error( 'invalid_billing_mode', 'Unsupported billing mode.' );
        }
        usort( $bookings, static function ( $a, $b ) {
            $date_order = strcmp( $a->booking_date, $b->booking_date );
            return $date_order !== 0 ? $date_order : strcmp( $a->ref, $b->ref );
        } );

        $groups = array();
        if ( $mode === 'monthly' ) {
            foreach ( $bookings as $booking ) {
                $key = substr( $booking->booking_date, 0, 7 );
                if ( ! isset( $groups[ $key ] ) ) {
                    $start = new DateTimeImmutable( $key . '-01', wp_timezone() );
                    $groups[ $key ] = array(
                        'key' => 'month-' . $key,
                        'label' => $start->format( 'F Y' ),
                        'start' => $start->format( 'Y-m-d' ),
                        'end' => $start->modify( 'last day of this month' )->format( 'Y-m-d' ),
                        'bookings' => array(),
                    );
                }
                $groups[ $key ]['bookings'][] = $booking;
            }
        } elseif ( $mode === 'upfront' ) {
            if ( $bookings ) {
                $first = reset( $bookings );
                $last = end( $bookings );
                $groups['upfront'] = array(
                    'key' => 'upfront-' . $first->booking_date . '-' . $last->booking_date,
                    'label' => 'Full series', 'start' => $first->booking_date, 'end' => $last->booking_date,
                    'bookings' => $bookings,
                );
            }
        } elseif ( $mode === 'legacy_per_occurrence' ) {
            foreach ( $bookings as $booking ) {
                $groups[ $booking->ref ] = array(
                    'key' => 'occurrence-' . $booking->ref,
                    'label' => wp_date( 'j F Y', strtotime( $booking->booking_date ) ),
                    'start' => $booking->booking_date, 'end' => $booking->booking_date,
                    'bookings' => array( $booking ),
                );
            }
        } else {
            $schedule = json_decode( (string) ( $series->billing_schedule_json ?? '' ), true );
            $terms = is_array( $schedule ) && ! empty( $schedule['terms'] ) && is_array( $schedule['terms'] )
                ? $schedule['terms']
                : array();
            if ( ! $terms ) return new WP_Error( 'term_schedule_required', 'Termly billing needs explicit term start and end dates; none are stored for this series.' );
            foreach ( $terms as $index => $term ) {
                if ( empty( $term['start'] ) || empty( $term['end'] ) ) return new WP_Error( 'invalid_term_schedule', 'Every term needs a start and end date.' );
                $term_start = self::real_local_date( $term['start'] );
                $term_end = self::real_local_date( $term['end'] );
                if ( ! $term_start || ! $term_end || $term_end < $term_start ) return new WP_Error( 'invalid_term_schedule', 'Every term needs real dates with the end on or after the start.' );
                $key = sanitize_key( $term['key'] ?? ( 'term-' . ( $index + 1 ) ) );
                $groups[ $key ] = array(
                    'key' => 'term-' . $key,
                    'label' => sanitize_text_field( $term['label'] ?? ucfirst( $key ) ),
                    'start' => $term_start->format( 'Y-m-d' ),
                    'end' => $term_end->format( 'Y-m-d' ),
                    'bookings' => array(),
                );
            }
            foreach ( $bookings as $booking ) {
                $matched = false;
                foreach ( $groups as &$group ) {
                    if ( $booking->booking_date >= $group['start'] && $booking->booking_date <= $group['end'] ) {
                        $group['bookings'][] = $booking;
                        $matched = true;
                        break;
                    }
                }
                unset( $group );
                if ( ! $matched ) return new WP_Error( 'term_metadata_incomplete', 'At least one booking falls outside the stored term dates; no schedule was inferred.' );
            }
            $groups = array_filter( $groups, static function ( $group ) { return ! empty( $group['bookings'] ); } );
        }

        $lead_days = max( 0, (int) ( $series->invoice_lead_days ?? 28 ) );
        $terms_days = max( 0, (int) ( $series->payment_terms_days ?? 14 ) );
        $periods = array();
        foreach ( $groups as $group ) {
            $start = new DateTimeImmutable( $group['start'], wp_timezone() );
            $issue = $start->modify( '-' . $lead_days . ' days' );
            $due = $issue->modify( '+' . $terms_days . ' days' );
            if ( $due > $start ) $due = $start;
            $total_minor = 0;
            $items = array();
            foreach ( $group['bookings'] as $booking ) {
                if ( is_float( $booking->amount ) ) return new WP_Error( 'float_booking_amount', 'Booking amount must be read as an exact decimal string.' );
                $minor = MBS_Money::from_decimal_string( (string) $booking->amount );
                if ( is_wp_error( $minor ) ) return $minor;
                $total_minor += $minor;
                $items[] = array(
                    'booking_ref' => $booking->ref,
                    'service_date' => $booking->booking_date,
                    'amount_minor' => $minor,
                    'description' => $booking->space . ' hire on ' . wp_date( 'j F Y', strtotime( $booking->booking_date ) ) . ( ! empty( $booking->kitchen ) ? ' (including kitchen)' : '' ),
                );
            }
            $periods[] = array(
                'period_key' => $group['key'], 'label' => $group['label'],
                'period_start' => $group['start'], 'period_end' => $group['end'],
                'issue_on' => $issue->format( 'Y-m-d' ), 'due_on' => $due->format( 'Y-m-d' ),
                'occurrence_count' => count( $items ), 'total_minor' => $total_minor, 'items' => $items,
            );
        }
        usort( $periods, static function ( $a, $b ) { return strcmp( $a['period_start'], $b['period_start'] ); } );
        return $periods;
    }

    /** Catch up every due invoice period, safely repeatable by cron or admin. */
    public static function catch_up( $as_of ) {
        global $wpdb;
        $as_of_date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $as_of, wp_timezone() );
        if ( ! $as_of_date || $as_of_date->format( 'Y-m-d' ) !== $as_of ) return new WP_Error( 'invalid_as_of_date', 'Catch-up date must be a real YYYY-MM-DD date.' );
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        $series_rows = $wpdb->get_results(
            "SELECT * FROM {$series_table}
             WHERE status = 'confirmed' AND billing_treatment = 'invoice_managed'
             AND billing_mode IN ('monthly','termly','legacy_per_occurrence','upfront')
             ORDER BY start_date ASC LIMIT 100"
        );
        $results = array();
        foreach ( $series_rows as $series ) {
            $preview = self::preview( $series->series_ref );
            if ( is_wp_error( $preview ) ) {
                $results[] = array( 'series_ref' => $series->series_ref, 'status' => 'error', 'message' => $preview->get_error_message() );
                continue;
            }
            foreach ( $preview['periods'] as $period ) {
                if ( $period['issue_on'] > $as_of ) continue;
                $generated = self::generate_period_invoice( $series, $period );
                $results[] = is_wp_error( $generated )
                    ? array( 'series_ref' => $series->series_ref, 'period_key' => $period['period_key'], 'status' => 'error', 'message' => $generated->get_error_message() )
                    : $generated;
            }
        }
        $credits = self::reconcile_cancelled_occurrences();
        return array( 'as_of' => $as_of, 'periods' => $results, 'credits' => is_wp_error( $credits ) ? array( 'error' => $credits->get_error_message() ) : $credits );
    }

    private static function generate_period_invoice( $series, $period ) {
        $period_key = 'series:' . $series->series_ref . ':period:' . $period['period_key'] . ':v1';
        $created = MBS_Billing_Ledger::create_draft_invoice( array(
            'series_ref' => $series->series_ref, 'contact_name' => $series->contact_name,
            'contact_organisation' => $series->contact_organisation, 'contact_email' => $series->contact_email,
            'contact_address' => $series->contact_address, 'billing_mode' => $series->billing_mode,
            'period_start' => $period['period_start'], 'period_end' => $period['period_end'],
            'currency' => 'GBP', 'due_at' => $period['due_on'] . ' 23:59:59',
        ), $period_key );
        if ( is_wp_error( $created ) ) return $created;
        $invoice = $created['invoice'];
        if ( $invoice->status !== 'draft' ) {
            return array( 'series_ref' => $series->series_ref, 'period_key' => $period['period_key'], 'status' => 'existing', 'invoice_ref' => $invoice->invoice_ref );
        }

        foreach ( $period['items'] as $item ) {
            if ( MBS_Billing_Ledger::has_booking_item( $invoice->id, $item['booking_ref'] ) ) continue;
            $booking = MBS_Bookings::get( $item['booking_ref'] );
            if ( ! $booking || in_array( $booking->status, array( 'cancelled', 'archived' ), true ) ) continue;
            $added = MBS_Billing_Ledger::add_item( $invoice->invoice_ref, array(
                'item_type' => 'hire', 'booking_ref' => $booking->ref, 'service_date' => $booking->booking_date,
                'description' => $item['description'], 'quantity_milli' => 1000,
                'unit_amount_minor' => $item['amount_minor'],
                'pricing_snapshot' => array(
                    'booking_ref' => $booking->ref, 'source_amount_decimal' => (string) $booking->amount,
                    'pricing_tier' => $booking->pricing_tier, 'space' => $booking->space,
                    'kitchen' => (bool) $booking->kitchen, 'captured_at' => current_time( 'mysql' ),
                ),
            ), (int) $invoice->version );
            if ( is_wp_error( $added ) ) {
                // A concurrent worker may have inserted it after our check.
                $invoice = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
                if ( MBS_Billing_Ledger::has_booking_item( $invoice->id, $booking->ref ) ) continue;
                return $added;
            }
            $invoice = $added['invoice'];
        }
        $issued = MBS_Billing_Ledger::issue_invoice( $invoice->invoice_ref, (int) $invoice->version );
        if ( is_wp_error( $issued ) ) return $issued;
        return array(
            'series_ref' => $series->series_ref, 'period_key' => $period['period_key'],
            'status' => $created['created'] ? 'created' : 'resumed', 'invoice_ref' => $invoice->invoice_ref,
            'occurrence_count' => $period['occurrence_count'], 'total_minor' => $period['total_minor'],
        );
    }

    /** Credit cancelled occurrences that were already frozen onto issued bills. */
    public static function reconcile_cancelled_occurrences() {
        global $wpdb;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $rows = $wpdb->get_results(
            "SELECT a.invoice_id, a.booking_ref, i.invoice_ref,
                    COALESCE(SUM(ii.line_total_minor), 0) AS amount_minor
             FROM {$allocation_table} a
             INNER JOIN {$booking_table} b ON b.ref = a.booking_ref
             INNER JOIN {$invoice_table} i ON i.id = a.invoice_id
             INNER JOIN {$item_table} ii ON ii.invoice_id = a.invoice_id AND ii.booking_ref = a.booking_ref
             WHERE a.status = 'active' AND b.status = 'cancelled'
             AND i.document_type = 'invoice' AND i.status IN ('issued','part_paid','paid')
             GROUP BY a.invoice_id, a.booking_ref, i.invoice_ref"
        );
        $results = array();
        foreach ( $rows as $row ) {
            $amount = (int) $row->amount_minor;
            if ( $amount <= 0 ) continue;
            $credit = MBS_Billing_Ledger::create_credit_note(
                $row->invoice_ref, $amount, 'Cancelled booking ' . $row->booking_ref,
                'cancelled-booking:' . $row->invoice_ref . ':' . $row->booking_ref
            );
            if ( is_wp_error( $credit ) ) {
                $results[] = array( 'booking_ref' => $row->booking_ref, 'status' => 'error', 'message' => $credit->get_error_message() );
                continue;
            }
            MBS_Billing_Ledger::release_booking_allocation( $row->invoice_id, $row->booking_ref );
            $results[] = array( 'booking_ref' => $row->booking_ref, 'status' => $credit['created'] ? 'credited' : 'existing', 'credit_ref' => $credit['credit_note']->invoice_ref );
        }
        return $results;
    }

    private static function billable_occurrences( $series_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE series_id = %s AND status IN ('confirmed','deposit_paid','paid')
             ORDER BY booking_date ASC, id ASC",
            sanitize_text_field( $series_ref )
        ) );
    }

    private static function real_local_date( $value ) {
        $value = trim( (string) $value );
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
            return false;
        }
        return $date;
    }
}
