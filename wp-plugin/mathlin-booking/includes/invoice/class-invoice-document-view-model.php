<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rendering context combining the immutable snapshot with optional live state.
 *
 * The Issued Invoice renderer accepts this with $account_state = null.
 * The Current Account renderer uses both.
 */
class MBS_Invoice_Document_View_Model {

    /** @var MBS_Issued_Invoice_Snapshot Immutable issue-time data */
    public $snapshot;

    /** @var MBS_Current_Account_State|null Live payment/balance state (null = issued-only mode) */
    public $account_state;

    /** @var string Rendering mode: 'issued' | 'current_account' */
    public $mode = 'issued';

    /** @var bool True for pre-migration documents without genuine snapshots */
    public $is_legacy_reconstruction = false;

    /** @var string Brand accent colour */
    public $accent_colour = '#7413DC';

    /**
     * @param MBS_Issued_Invoice_Snapshot    $snapshot
     * @param MBS_Current_Account_State|null $account_state
     * @param string                         $mode
     */
    public function __construct( $snapshot, $account_state = null, $mode = 'issued' ) {
        $this->snapshot = $snapshot;
        $this->account_state = $account_state;
        $this->mode = $mode;
    }

    /**
     * Create a view model for the canonical Issued Invoice (immutable).
     *
     * @param MBS_Issued_Invoice_Snapshot $snapshot
     * @return self
     */
    public static function issued( $snapshot ) {
        return new self( $snapshot, null, 'issued' );
    }

    /**
     * Create a view model for the Current Account View (snapshot + live state).
     *
     * @param MBS_Issued_Invoice_Snapshot $snapshot
     * @param MBS_Current_Account_State   $account_state
     * @return self
     */
    public static function current_account( $snapshot, $account_state ) {
        return new self( $snapshot, $account_state, 'current_account' );
    }

    /**
     * Create a view model for a legacy document without a genuine snapshot.
     *
     * @param MBS_Issued_Invoice_Snapshot   $reconstructed_snapshot
     * @param MBS_Current_Account_State|null $account_state
     * @param string                         $mode
     * @return self
     */
    public static function legacy_reconstruction( $reconstructed_snapshot, $account_state = null, $mode = 'current_account' ) {
        $vm = new self( $reconstructed_snapshot, $account_state, $mode );
        $vm->is_legacy_reconstruction = true;
        return $vm;
    }
}
