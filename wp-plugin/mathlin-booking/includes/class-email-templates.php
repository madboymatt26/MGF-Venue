<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Templates — configurable email content with placeholder tags.
 *
 * Available placeholders (replaced at send time):
 *   {name}           — Booker's full name
 *   {organisation}   — Organisation/group name
 *   {ref}            — Booking reference (e.g. MBS-ABC123)
 *   {space}          — Space name (e.g. Main Scout Hall)
 *   {date}           — Formatted booking date (e.g. Saturday 10 May 2026)
 *   {time}           — Time range or "All day"
 *   {attendees}      — Number of attendees
 *   {purpose}        — Purpose of booking
 *   {amount}         — Amount with £ sign (e.g. £75.00)
 *   {invoice}        — Invoice number
 *   {admin_email}    — Admin contact email
 *   {phone}          — Admin phone number
 *   {org_name}       — Organisation name (from settings)
 *   {org_address}    — Organisation address (from settings)
 *   {charity_number} — Charity registration number
 *   {bank_details}   — Formatted bank transfer details
 *   {pay_url}        — Online payment URL (if WooCommerce active)
 *   {reason}         — Cancellation reason (cancellation emails only)
 */
class MBS_Email_Templates {

    /**
     * Get all template types and their defaults.
     */
    public static function get_template_types() {
        return array(
            'booking_received' => array(
                'label'   => 'Booking Received (to booker)',
                'subject' => 'Booking Request Received – {ref}',
                'body'    => "Hi {name},\n\nWe've received your booking request for {space} on {date}.\n\nYour booking reference is: {ref}\n\nWe'll be in touch shortly to confirm your booking and send an invoice.\n\nIf you have any questions, please contact us at {admin_email} or call {phone}.",
            ),
            'admin_notification' => array(
                'label'   => 'New Booking Alert (to admin)',
                'subject' => '[New Booking] {ref} – {name}',
                'body'    => "A new booking has been submitted and is awaiting your confirmation.\n\nName: {name}\nOrganisation: {organisation}\nSpace: {space}\nDate: {date}\nTime: {time}\nAttendees: {attendees}\nPurpose: {purpose}\nAmount: {amount}",
            ),
            'booking_confirmed' => array(
                'label'   => 'Booking Confirmed (to booker)',
                'subject' => 'Booking Confirmed – {ref}',
                'body'    => "Hi {name},\n\nGreat news — your booking has been confirmed!\n\nInvoice Number: {invoice}\n\nPlease see the payment schedule below and the attached invoice for full details.\n\nIf you have any questions, please contact us at {admin_email}.",
            ),
            'booking_cancelled' => array(
                'label'   => 'Booking Cancelled (to booker)',
                'subject' => 'Booking Cancelled – {ref}',
                'body'    => "Hi {name},\n\nWe're sorry, but your booking for {space} on {date} has been cancelled.\n\n{reason}\n\nWe apologise for any inconvenience. If you'd like to rebook for a different date or have any questions, please contact us at {admin_email} or call {phone}.",
            ),
            'payment_received' => array(
                'label'   => 'Payment Received (to booker)',
                'subject' => 'Payment Received – {ref}',
                'body'    => "Hi {name},\n\nWe've received your payment for your booking. Thank you!\n\nYour booking is fully confirmed and paid. We look forward to seeing you!\n\nIf you have any questions, please contact us at {admin_email}.",
            ),
            'booking_reminder' => array(
                'label'   => 'Booking Reminder (to booker)',
                'subject' => 'Reminder: Your booking is coming up – {ref}',
                'body'    => "Hi {name},\n\nJust a friendly reminder that your booking is coming up soon.\n\nSpace: {space}\nDate: {date}\nTime: {time}\n\nLocation: {org_address}\n\nIf you need to make any changes or cancel, please contact us at {admin_email} or call {phone}.\n\nWe look forward to seeing you!",
            ),
            'chase_gentle' => array(
                'label'   => 'Payment Chase #1 — Friendly Reminder',
                'subject' => 'Payment Reminder – {invoice}',
                'body'    => "Hi {name},\n\nJust a gentle reminder that payment for your booking is now due.\n\nInvoice: {invoice}\nBooking: {space} on {date}\nAmount Due: {amount}\n\nPayment details:\n{bank_details}\n\nIf you have already made payment, please disregard this email. If you have any questions, contact us at {admin_email} or call {phone}.",
            ),
            'chase_overdue' => array(
                'label'   => 'Payment Chase #2 — Overdue',
                'subject' => 'Payment Overdue – {invoice}',
                'body'    => "Hi {name},\n\nOur records show that payment for your booking is now overdue. Please arrange payment at your earliest convenience.\n\nInvoice: {invoice}\nBooking: {space} on {date}\nAmount Due: {amount}\n\nPayment details:\n{bank_details}\n\nIf you have already made payment, please disregard this email. If you have any questions, contact us at {admin_email} or call {phone}.",
            ),
            'chase_urgent' => array(
                'label'   => 'Payment Chase #3 — Urgent Final Notice',
                'subject' => 'URGENT: Payment Required – {invoice}',
                'body'    => "Hi {name},\n\nThis is a final reminder. Payment for your booking is significantly overdue. Please arrange payment immediately to avoid your booking being cancelled.\n\nInvoice: {invoice}\nBooking: {space} on {date}\nAmount Due: {amount}\n\nPayment details:\n{bank_details}\n\nIf you have already made payment, please disregard this email. If you have any questions, contact us at {admin_email} or call {phone}.",
            ),
            'booking_edited' => array(
                'label'   => 'Booking Edited by Admin (to booker)',
                'subject' => 'Booking Updated – {ref}',
                'body'    => "Hi {name},\n\nYour booking has been updated. Here are the current details:\n\nSpace: {space}\nDate: {date}\nTime: {time}\nAmount: {amount}\n\nIf you have any questions, contact us at {admin_email}.",
            ),
            'recurring_summary' => array(
                'label'   => 'Recurring Booking Summary (to booker)',
                'subject' => 'Recurring Booking Submitted – {ref}',
                'body'    => "Hi {name},\n\nYour recurring booking request has been submitted.\n\nSpace: {space}\nTime: {time}\n\nEach booking is pending confirmation. We will review and confirm them shortly.\n\nIf you have any questions, contact us at {admin_email} or call {phone}.",
            ),
            'modification_approved' => array(
                'label'   => 'Change Request Approved (to booker)',
                'subject' => 'Booking Change Approved – {ref}',
                'body'    => "Hi {name},\n\nYour requested changes have been approved. Here are your updated booking details:\n\nSpace: {space}\nDate: {date}\nTime: {time}\nAmount: {amount}\n\nIf you have any questions, contact us at {admin_email}.",
            ),
            'modification_rejected' => array(
                'label'   => 'Change Request Declined (to booker)',
                'subject' => 'Booking Change Request Declined – {ref}',
                'body'    => "Hi {name},\n\nUnfortunately, we're unable to accommodate your change request for booking {ref}.\n\n{reason}\n\nYour booking remains unchanged. If you have any questions, please contact us at {admin_email} or call {phone}.",
            ),
            'admin_mod_request' => array(
                'label'   => 'Change Request Alert (to admin)',
                'subject' => '[Change Request] {ref} – {name}',
                'body'    => "A booker has submitted a change request for booking {ref}.\n\nName: {name}\nSpace: {space}\nDate: {date}\nAmount: {amount}\n\nPlease review and approve or reject the request.",
            ),
        );
    }

    /**
     * Get a specific template (user-customised or default).
     *
     * @param string $type  Template type key
     * @return array  { 'subject' => '...', 'body' => '...' }
     */
    public static function get_template( $type ) {
        $defaults = self::get_template_types();
        if ( ! isset( $defaults[ $type ] ) ) return null;

        $saved = get_option( 'mbs_email_template_' . $type, array() );

        return array(
            'subject' => ! empty( $saved['subject'] ) ? $saved['subject'] : $defaults[ $type ]['subject'],
            'body'    => ! empty( $saved['body'] )    ? $saved['body']    : $defaults[ $type ]['body'],
        );
    }

    /**
     * Save a customised template.
     */
    public static function save_template( $type, $subject, $body ) {
        update_option( 'mbs_email_template_' . $type, array(
            'subject' => sanitize_text_field( $subject ),
            'body'    => sanitize_textarea_field( $body ),
        ) );
    }

    /**
     * Reset a template to its default.
     */
    public static function reset_template( $type ) {
        delete_option( 'mbs_email_template_' . $type );
    }

    /**
     * Replace placeholders in a template string.
     *
     * @param string       $text     Template text with {placeholders}
     * @param array|object $booking  Booking data (array for new bookings, object for existing)
     * @param array        $extra    Additional replacements (e.g. {reason})
     * @return string
     */
    public static function replace_placeholders( $text, $booking, $extra = array() ) {
        $org = self::get_org_settings();
        $bank = MBS_Bookings::get_bank_details();

        // Normalise booking data to array
        if ( is_object( $booking ) ) {
            $b = array(
                'name'         => $booking->name,
                'organisation' => $booking->organisation ?? '',
                'ref'          => $booking->ref,
                'space'        => $booking->space,
                'booking_date' => $booking->booking_date,
                'start_time'   => $booking->start_time ?? '',
                'end_time'     => $booking->end_time ?? '',
                'all_day'      => ! empty( $booking->all_day ),
                'attendees'    => $booking->attendees,
                'purpose'      => $booking->purpose,
                'amount'       => $booking->amount,
                'invoice'      => $booking->invoice_number ?? '',
            );
        } else {
            $b = array(
                'name'         => $booking['name'] ?? '',
                'organisation' => $booking['organisation'] ?? '',
                'ref'          => $booking['ref'] ?? '',
                'space'        => $booking['space'] ?? '',
                'booking_date' => $booking['booking_date'] ?? '',
                'start_time'   => $booking['start_time'] ?? '',
                'end_time'     => $booking['end_time'] ?? '',
                'all_day'      => ! empty( $booking['all_day'] ),
                'attendees'    => $booking['attendees'] ?? '',
                'purpose'      => $booking['purpose'] ?? '',
                'amount'       => $booking['amount'] ?? 0,
                'invoice'      => $booking['invoice_number'] ?? '',
            );
        }

        // Build time string
        $time = $b['all_day'] ? 'All day' : ( $b['start_time'] . ' – ' . $b['end_time'] );

        // Build bank details string
        $bank_str = "Sort Code: {$bank['sort_code']}\nAccount: {$bank['account_number']}\nReference: {$b['invoice']}";

        // Build pay URL
        $pay_url = '';
        if ( is_object( $booking ) && MBS_Woo_Payment::is_available() ) {
            $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
        }

        $replacements = array(
            '{name}'           => $b['name'],
            '{organisation}'   => $b['organisation'] ?: '—',
            '{ref}'            => $b['ref'],
            '{space}'          => $b['space'],
            '{date}'           => $b['booking_date'] ? date( 'l j F Y', strtotime( $b['booking_date'] ) ) : '',
            '{time}'           => $time,
            '{attendees}'      => $b['attendees'],
            '{purpose}'        => $b['purpose'],
            '{amount}'         => '£' . number_format( (float) $b['amount'], 2 ),
            '{invoice}'        => $b['invoice'],
            '{admin_email}'    => MBS_Bookings::get_admin_email(),
            '{phone}'          => $org['phone'],
            '{org_name}'       => $org['name'],
            '{org_address}'    => $org['address'],
            '{charity_number}' => $org['charity_number'],
            '{bank_details}'   => $bank_str,
            '{pay_url}'        => $pay_url,
        );

        // Merge extra replacements (e.g. {reason} for cancellations)
        $replacements = array_merge( $replacements, $extra );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
    }

    /**
     * Get organisation settings (configurable).
     */
    public static function get_org_settings() {
        return array(
            'name'           => get_option( 'mbs_org_name', 'Needham Market Scout Group' ),
            'address'        => get_option( 'mbs_org_address', 'Scout Hall, Crown St, Needham Market, IP6 8RY' ),
            'phone'          => get_option( 'mbs_org_phone', '01449 797577' ),
            'charity_number' => get_option( 'mbs_org_charity_number', '1038177' ),
            'logo_url'       => get_option( 'mbs_org_logo_url', '' ),
        );
    }

    /**
     * Get the logo HTML for emails — uses uploaded image or falls back to text.
     */
    public static function get_logo_html() {
        $org = self::get_org_settings();
        if ( ! empty( $org['logo_url'] ) ) {
            return '<img src="' . esc_url( $org['logo_url'] ) . '" alt="' . esc_attr( $org['name'] ) . '" style="max-height:50px;max-width:200px;margin-bottom:8px;">';
        }
        return '<span style="font-size:2rem;">&#9884;</span>';
    }

    /**
     * Save organisation settings.
     */
    public static function save_org_settings( $data ) {
        if ( isset( $data['org_name'] ) )           update_option( 'mbs_org_name', sanitize_text_field( $data['org_name'] ) );
        if ( isset( $data['org_address'] ) )        update_option( 'mbs_org_address', sanitize_text_field( $data['org_address'] ) );
        if ( isset( $data['org_phone'] ) )          update_option( 'mbs_org_phone', sanitize_text_field( $data['org_phone'] ) );
        if ( isset( $data['org_charity_number'] ) ) update_option( 'mbs_org_charity_number', sanitize_text_field( $data['org_charity_number'] ) );
        if ( isset( $data['org_logo_url'] ) )       update_option( 'mbs_org_logo_url', esc_url_raw( $data['org_logo_url'] ) );
    }

    /**
     * Get chase email settings.
     */
    public static function get_chase_settings() {
        return array(
            'max_chases'     => (int) get_option( 'mbs_max_chase_emails', 3 ),
            'chase_interval' => (int) get_option( 'mbs_chase_interval_days', 3 ),
            'cron_reminders' => get_option( 'mbs_cron_time_reminders', '07:00' ),
            'cron_chase'     => get_option( 'mbs_cron_time_chase', '09:00' ),
            'cron_archive'   => get_option( 'mbs_cron_time_archive', '02:00' ),
        );
    }

    /**
     * Save chase email settings.
     */
    public static function save_chase_settings( $data ) {
        if ( isset( $data['max_chase_emails'] ) )      update_option( 'mbs_max_chase_emails',      max( 1, min( 10, absint( $data['max_chase_emails'] ) ) ) );
        if ( isset( $data['chase_interval_days'] ) )   update_option( 'mbs_chase_interval_days',   max( 1, min( 14, absint( $data['chase_interval_days'] ) ) ) );
        if ( isset( $data['cron_time_reminders'] ) )   update_option( 'mbs_cron_time_reminders',   sanitize_text_field( $data['cron_time_reminders'] ) );
        if ( isset( $data['cron_time_chase'] ) )       update_option( 'mbs_cron_time_chase',       sanitize_text_field( $data['cron_time_chase'] ) );
        if ( isset( $data['cron_time_archive'] ) )     update_option( 'mbs_cron_time_archive',     sanitize_text_field( $data['cron_time_archive'] ) );
    }
}
