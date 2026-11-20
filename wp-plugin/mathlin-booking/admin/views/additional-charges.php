<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<nav class="mbs-charge-breadcrumbs" aria-label="Charge archive views">
<?php foreach(array('active'=>'Active','archived'=>'Archived','all'=>'All charges') as $archive_key=>$archive_label): ?>
<a class="button <?php echo $archive_view===$archive_key?'button-primary':''; ?>" href="<?php echo esc_url(self::admin_url(array('related_ref'=>$related,'archive_view'=>$archive_key,'charge_search'=>$search,'charge_status'=>$filter_status))); ?>" <?php echo $archive_view===$archive_key?'aria-current="page"':''; ?>><?php echo esc_html($archive_label); ?></a>
<?php endforeach; ?>
</nav>
<div class="nms-filters">
    <form method="get">
        <input type="hidden" name="page" value="mathlin-additional-charges">
        <input type="hidden" name="related_ref" value="<?php echo esc_attr($related); ?>">
        <input type="hidden" name="archive_view" value="<?php echo esc_attr($archive_view); ?>">
        <label class="screen-reader-text" for="mbs-charge-search">Search additional charges</label>
        <input type="search" id="mbs-charge-search" class="nms-search-input" name="charge_search" value="<?php echo esc_attr($search); ?>" placeholder="Invoice, booking, hirer or description">
        <label class="screen-reader-text" for="mbs-charge-status">Status</label>
        <select id="mbs-charge-status" name="charge_status"><option value="">All statuses</option>
            <?php foreach(array('draft'=>'Draft','issued'=>'Awaiting payment','part_paid'=>'Part paid','paid'=>'Paid','credited'=>'Credited','void'=>'Discarded','overdue'=>'Overdue','payment_review'=>'Payment review') as $value=>$label): ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($filter_status,$value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button">Filter</button>
        <?php if($search||$filter_status): ?><a class="button" href="<?php echo esc_url(self::admin_url(array('related_ref'=>$related,'archive_view'=>$archive_view))); ?>">Clear</a><?php endif; ?>
    </form>
</div>
<div class="mbs-series-table-wrap mbs-charge-table-wrap">
<table class="wp-list-table widefat striped nms-bookings-table mbs-charge-table">
    <thead><tr><th scope="col">Invoice / description</th><th scope="col">Hirer</th><th scope="col">Original booking</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Balance</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col">Email</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="10"><div class="nms-empty">No additional charges match this filter.</div></td></tr><?php endif; ?>
    <?php
    $preloaded = self::preload_list_context( $rows );
    $email_labels = array( 'pending' => 'Queued', 'processing' => 'Sending', 'sent' => 'Accepted', 'failed' => 'Failed' );
    foreach ( $rows as $invoice ) :
        $inv_id = (int) $invoice->id;
        if ( isset( $preloaded['items'][ $inv_id ] ) ) {
            $items = $preloaded['items'][ $inv_id ];
            $meta  = $preloaded['meta'][ $inv_id ] ?? array();
        } else {
            $items = MBS_Billing_Ledger::get_items( $invoice->id );
            $meta  = ( count( $items ) === 1 && empty( $items[0]->booking_ref ) )
                ? ( json_decode( (string) ( $items[0]->pricing_snapshot_json ?? '' ), true ) ?: array() )
                : array();
        }
        $item = $items ? $items[0] : null;
        $related_ref = $meta['related_ref'] ?? '';
        $context = ( $related_ref !== '' && isset( $preloaded['related'][ $related_ref ] ) )
            ? $preloaded['related'][ $related_ref ]
            : self::related_context( $related_ref );
        $detail = self::admin_url( array( 'invoice_ref' => $invoice->invoice_ref ) );
        $amount = $invoice->status === 'draft' && $item ? (int) $item->line_total_minor : (int) $invoice->total_minor;
        $last = $preloaded['delivery'][ $inv_id ] ?? null;
        $email_status = $last ? ( $email_labels[ $last['status'] ] ?? $last['status'] ) : 'Not sent';
        $is_archived = array_key_exists( $inv_id, $preloaded['archived'] )
            ? $preloaded['archived'][ $inv_id ]
            : self::is_archived( $invoice );
        $res_status = $preloaded['reservations'][ $invoice->invoice_ref ] ?? 'none';
        $can_archive_row = self::is_charge( $invoice )
            && in_array( $invoice->status, array( 'void', 'paid', 'credited' ), true )
            && MBS_Billing_Ledger::balance_minor( $invoice ) === 0
            && ! in_array( $res_status, array( 'active', 'bound', 'reconciliation_required' ), true );
    ?><tr>
        <td data-label="Invoice / description"><a href="<?php echo esc_url($detail); ?>"><strong><?php echo esc_html($invoice->invoice_ref); ?></strong></a><br><span class="nms-muted"><?php echo esc_html($item?$item->description:'Incomplete draft'); ?></span></td>
        <td data-label="Hirer"><strong><?php echo esc_html($invoice->contact_name); ?></strong><?php if($invoice->contact_organisation): ?><br><?php echo esc_html($invoice->contact_organisation); ?><?php endif; ?></td>
        <td data-label="Original booking"><?php if($context['url']): ?><a href="<?php echo esc_url($context['url']); ?>"><?php echo esc_html($context['label']); ?></a><?php else: echo esc_html($context['label']); endif; ?><br><small class="nms-muted"><?php echo esc_html($context['summary']); ?></small></td>
        <td data-label="Amount"><?php echo esc_html(MBS_Money::format($amount)); ?></td>
        <td data-label="Paid"><?php echo esc_html(MBS_Money::format((int)$invoice->paid_minor)); ?></td>
        <td data-label="Balance"><strong><?php echo esc_html($invoice->status==='draft'?'—':MBS_Money::format(MBS_Billing_Ledger::balance_minor($invoice))); ?></strong></td>
        <td data-label="Due"><?php echo esc_html(substr($invoice->due_at,0,10)); ?></td>
        <td data-label="Status"><span class="mbs-series-badge"><?php echo esc_html(self::status_label($invoice)); ?></span><?php if($is_archived): ?><br><small><?php echo $can_archive_row?'Archived':'Archived — needs attention'; ?></small><?php endif; ?></td>
        <td data-label="Email"><?php if ( $last ) :
            $badge_mod = $last['status'] === 'failed' ? 'mbs-email-badge--failed' : ( $last['status'] === 'pending' || $last['status'] === 'processing' ? 'mbs-email-badge--pending' : 'mbs-email-badge--sent' );
            $badge_tab = $last['status'] === 'failed' ? 'transport' : ( $last['status'] === 'sent' ? 'sent' : 'pending' );
        ?><button type="button" class="mbs-email-badge <?php echo esc_attr( $badge_mod ); ?> mbs-open-email-diagnostics" data-tab="<?php echo esc_attr( $badge_tab ); ?>" data-reference-type="invoice" data-reference-id="<?php echo (int) $invoice->id; ?>" data-context-label="<?php echo esc_attr( 'Charge ' . $invoice->invoice_ref ); ?>" title="Accepted means mail-server acceptance, not proof of inbox delivery. Click to inspect email diagnostics."><?php echo esc_html( $email_status ); ?></button><?php else : ?><span title="No email queued yet."><?php echo esc_html( $email_status ); ?></span><?php endif; ?></td>
        <td data-label="Actions"><a class="button button-small" href="<?php echo esc_url($detail); ?>">View charge</a></td>
    </tr><?php endforeach; ?>
    </tbody></table>
</div>
<nav class="tablenav" aria-label="Additional charge pages">
    <?php $page_args=array('related_ref'=>$related,'charge_search'=>$search,'charge_status'=>$filter_status,'archive_view'=>$archive_view); ?>
    <?php if($page>1): ?><a class="button" href="<?php echo esc_url(self::admin_url(array_merge($page_args,array('charge_page'=>$page-1)))); ?>">Previous</a><?php endif; ?>
    <span>Page <?php echo (int)$page; ?></span>
    <?php if($has_next): ?><a class="button" href="<?php echo esc_url(self::admin_url(array_merge($page_args,array('charge_page'=>$page+1)))); ?>">Next</a><?php endif; ?>
</nav>
