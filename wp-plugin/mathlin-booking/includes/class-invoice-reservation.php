<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Durable, auditable invoice-checkout claim state machine. */
class MBS_Invoice_Reservation {
    const TTL = 1200;

    public static function acquire( $invoice, $existing_ref = '' ) {
        global $wpdb;
        if ( ! MBS_Invoice_Payment::is_payable( $invoice ) ) return new WP_Error( 'invoice_not_payable', 'This invoice is not available for payment.' );
	        $table = self::table(); $now = self::utc_now(); $expires = self::utc_after( self::TTL );
	        $current = self::get( $invoice->invoice_ref );
	        $balance_version=isset($invoice->version)?(int)$invoice->version:0;
	        /** Observable critical window used by deterministic multi-process tests. */
	        if(function_exists('do_action'))do_action('mbs_invoice_reservation_critical_section',$invoice->invoice_ref,$balance_version);
	        if ( $current && $existing_ref && (int)($current->balance_version??0)===$balance_version && hash_equals( $current->reservation_ref, (string) $existing_ref ) && in_array( $current->status, array( 'active', 'bound' ), true ) ) {
            if ( $current->status === 'active' ) {
                $renewed = self::renew( $invoice->invoice_ref, $existing_ref );
                if ( $renewed ) return (array) self::get( $invoice->invoice_ref );
            } else return (array) $current;
        }
	        if ( $current && in_array( $current->status, array( 'bound', 'reconciliation_required' ), true ) ) return new WP_Error( 'invoice_payment_reserved', 'This invoice already has an authoritative payment order.' );
	        if ( $current && in_array($current->status,array('captured','refunded'),true) && (int)($current->balance_version??0) >= $balance_version ) return new WP_Error( 'invoice_payment_reserved', 'This invoice balance generation already has an authoritative payment order.' );
	        $ref = self::reference();
        if ( ! $current ) {
	            $inserted = $wpdb->insert( $table, array( 'reservation_ref' => $ref, 'invoice_id' => (int) $invoice->id, 'invoice_ref' => $invoice->invoice_ref, 'order_id' => null, 'amount_minor' => MBS_Billing_Ledger::balance_minor( $invoice ), 'status' => 'active', 'version' => 1, 'balance_version'=>$balance_version, 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now ) );
            if ( $inserted !== false ) { self::schedule_expiry( $invoice->invoice_ref, $ref ); return (array) self::get( $invoice->invoice_ref ); }
        } else {
            // Only an unbound expired/released claim can be replaced. The old
            // token and version are part of the compare-and-swap predicate.
            $updated = $wpdb->query( $wpdb->prepare(
	                "UPDATE {$table} SET reservation_ref=%s, order_id=NULL, amount_minor=%d, status='active', version=version+1, balance_version=%d, expires_at=%s, last_error='', updated_at=%s
	                 WHERE invoice_id=%d AND reservation_ref=%s AND version=%d
	                 AND ((order_id IS NULL AND (status='released' OR (status='active' AND expires_at<=%s)))
	                      OR (status IN ('captured','refunded') AND balance_version<%d))",
	                $ref, MBS_Billing_Ledger::balance_minor( $invoice ), $balance_version, $expires, $now, (int) $invoice->id,
	                $current->reservation_ref, (int) $current->version, $now, $balance_version
            ) );
            if ( $updated === 1 ) { self::schedule_expiry( $invoice->invoice_ref, $ref ); return (array) self::get( $invoice->invoice_ref ); }
        }
        return new WP_Error( 'invoice_payment_reserved', 'Another checkout owns this invoice balance.' );
    }

    public static function bind_order( $invoice_ref, $reservation_ref, $order_id ) {
	        global $wpdb; $table=self::table(); $now=self::utc_now();
        $claim=self::get($invoice_ref);
        if(!$claim||!hash_equals($claim->reservation_ref,(string)$reservation_ref))return new WP_Error('invoice_reservation_order_conflict','This invoice reservation is no longer authoritative.');
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET order_id=%d,status='bound',version=version+1,expires_at=NULL,updated_at=%s WHERE invoice_ref=%s AND reservation_ref=%s AND version=%d AND status='active' AND order_id IS NULL AND expires_at>%s",(int)$order_id,$now,$invoice_ref,$reservation_ref,(int)$claim->version,$now));
        if($updated===1)return (array)self::get($invoice_ref);
        $current=self::get($invoice_ref);
        if($current&&$current->status==='bound'&&(int)$current->order_id===(int)$order_id&&hash_equals($current->reservation_ref,(string)$reservation_ref))return (array)$current;
        return new WP_Error('invoice_reservation_order_conflict','This invoice reservation is already bound to another order.');
    }

    public static function validate( $invoice_ref, $reservation_ref, $amount_minor, $order_id = 0 ) {
        $r=self::get($invoice_ref); if(!$r||!hash_equals($r->reservation_ref,(string)$reservation_ref)||(int)$r->amount_minor!==(int)$amount_minor)return false;
        if($order_id)return $r->status==='bound'&&(int)$r->order_id===(int)$order_id;
        return $r->status==='active'&&!$r->order_id&&strtotime($r->expires_at)>time();
    }

    public static function renew( $invoice_ref, $reservation_ref ) {
        global $wpdb;$table=self::table();$row=self::get($invoice_ref);if(!$row||$row->status!=='active'||$row->order_id||!hash_equals($row->reservation_ref,(string)$reservation_ref)||strtotime($row->expires_at)<=time())return false;
	        $expires=self::utc_after(self::TTL);$now=self::utc_now();$updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET expires_at=%s,version=version+1,updated_at=%s WHERE invoice_ref=%s AND reservation_ref=%s AND version=%d AND status='active' AND order_id IS NULL AND expires_at>%s",$expires,$now,$invoice_ref,$reservation_ref,(int)$row->version,$now));
        if($updated===1){self::schedule_expiry($invoice_ref,$reservation_ref);return true;}return false;
    }

	    public static function complete( $invoice_ref, $reservation_ref, $order_id ) { return self::transition($invoice_ref,$reservation_ref,$order_id,array('bound','reconciliation_required'),'captured',''); }
	    public static function refunded( $invoice_ref, $order_id ) { $row=self::get($invoice_ref); if(!$row||(int)$row->order_id!==(int)$order_id)return false; if($row->status==='refunded')return true; return self::transition($invoice_ref,$row->reservation_ref,$order_id,array('captured'),'refunded','WooCommerce refund recorded'); }
    public static function reconciliation_required( $invoice_ref, $reservation_ref, $order_id, $message ) { return self::transition($invoice_ref,$reservation_ref,$order_id,array('bound','reconciliation_required'),'reconciliation_required',$message); }
    public static function resolve( $invoice_ref, $reservation_ref, $order_id, $resolution ) {
        if(!in_array($resolution,array('ledger_recorded','refund_confirmed'),true))return new WP_Error('invalid_resolution','Resolution must confirm a ledger record or refund.');
        global $wpdb;
        $reservation=self::get($invoice_ref);
        if(!$reservation||!hash_equals($reservation->reservation_ref,(string)$reservation_ref)||(int)$reservation->order_id!==(int)$order_id)return new WP_Error('reconciliation_owner_changed','The reservation owner changed; refresh before resolving.');
        if($resolution==='ledger_recorded'){
            $transaction_table=$wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE;
            $payment=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$transaction_table} WHERE invoice_id=%d AND provider='woocommerce' AND provider_transaction_id=%s AND transaction_type='payment' AND status='completed'",(int)$reservation->invoice_id,(string)$order_id));
            if(!$payment)return new WP_Error('reconciliation_payment_missing','No completed ledger payment exists for this order. Record it safely before resolving.');
        }else{
            $order=function_exists('wc_get_order')?wc_get_order($order_id):null;$refunded=0;
            if($order)foreach($order->get_refunds() as $refund)$refunded+=(int)round((float)$refund->get_amount()*100);
            if($refunded<(int)$reservation->amount_minor)return new WP_Error('reconciliation_refund_missing','WooCommerce does not show a confirmed full refund for this captured amount.');
        }
        return self::transition($invoice_ref,$reservation_ref,$order_id,array('reconciliation_required'),$resolution==='ledger_recorded'?'captured':'refunded','Resolved by administrator '.get_current_user_id());
    }

    public static function release( $invoice_ref, $reservation_ref, $reason = 'released', $order_id = 0 ) {
        global $wpdb;$table=self::table();$r=self::get($invoice_ref);if(!$r||!hash_equals($r->reservation_ref,(string)$reservation_ref))return false;
        $where="invoice_ref=%s AND reservation_ref=%s AND version=%d AND status IN ('active','bound')";$args=array($invoice_ref,$reservation_ref,(int)$r->version);
        if($r->status==='bound'){if(!$order_id||(int)$r->order_id!==(int)$order_id)return false;$where.=' AND order_id=%d';$args[]=(int)$order_id;}else{$where.=' AND order_id IS NULL';}
	        array_unshift($args,"UPDATE {$table} SET order_id=NULL,status='released',version=version+1,last_error=%s,updated_at=%s WHERE {$where}",sanitize_text_field($reason),self::utc_now());
        return $wpdb->query(call_user_func_array(array($wpdb,'prepare'),$args))===1;
    }
    public static function release_order($order_id){$o=function_exists('wc_get_order')?wc_get_order($order_id):null;if(!$o)return false;return self::release((string)$o->get_meta('_mbs_invoice_ref'),(string)$o->get_meta('_mbs_invoice_reservation_ref'),'order_cancelled',(int)$order_id);}
    public static function release_expired($invoice_ref,$reservation_ref){global $wpdb;$r=self::get($invoice_ref);if(!$r||$r->status!=='active'||$r->order_id||strtotime($r->expires_at)>time())return false;return self::release($invoice_ref,$reservation_ref,'expired',0);}
    public static function get($invoice_ref){global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE invoice_ref=%s',$invoice_ref));}
	    private static function transition($invoice_ref,$reservation_ref,$order_id,$from,$to,$error){global $wpdb;$table=self::table();$r=self::get($invoice_ref);if(!$r||!hash_equals($r->reservation_ref,(string)$reservation_ref)||(int)$r->order_id!==(int)$order_id||!in_array($r->status,$from,true))return false;$states="'".implode("','",array_map('esc_sql',$from))."'";return $wpdb->query($wpdb->prepare("UPDATE {$table} SET status=%s,version=version+1,last_error=%s,updated_at=%s WHERE invoice_ref=%s AND reservation_ref=%s AND order_id=%d AND version=%d AND status IN ({$states})",$to,sanitize_text_field($error),self::utc_now(),$invoice_ref,$reservation_ref,(int)$order_id,(int)$r->version))===1;}
    private static function table(){global $wpdb;return $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE;}
	    private static function schedule_expiry($invoice_ref,$reservation_ref){if(function_exists('wp_schedule_single_event'))wp_schedule_single_event(time()+self::TTL,'mbs_release_invoice_reservation',array($invoice_ref,$reservation_ref));}
	    private static function utc_now(){return gmdate('Y-m-d H:i:s');}
	    private static function utc_after($seconds){return gmdate('Y-m-d H:i:s',time()+(int)$seconds);}
    private static function reference(){try{return 'RSV-'.strtoupper(bin2hex(random_bytes(12)));}catch(Exception $e){return 'RSV-'.strtoupper(wp_generate_password(24,false,false));}}
}
