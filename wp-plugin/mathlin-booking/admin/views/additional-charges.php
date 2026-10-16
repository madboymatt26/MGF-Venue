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
    <?php foreach($rows as $invoice):
        $meta=self::metadata($invoice);$items=MBS_Billing_Ledger::get_items($invoice->id);$item=$items?$items[0]:null;
        $context=self::related_context($meta['related_ref']??'');$detail=self::admin_url(array('invoice_ref'=>$invoice->invoice_ref));
        $amount=$invoice->status==='draft'&&$item?(int)$item->line_total_minor:(int)$invoice->total_minor;
        $delivery=self::delivery_history($invoice);$last=$delivery?$delivery[0]:null;
        $email_labels=array('pending'=>'Queued','processing'=>'Sending','sent'=>'Accepted','failed'=>'Failed');
        $email_status=$last?($email_labels[$last['status']]??$last['status']):'Not sent';
    ?><tr>
        <td data-label="Invoice / description"><a href="<?php echo esc_url($detail); ?>"><strong><?php echo esc_html($invoice->invoice_ref); ?></strong></a><br><span class="nms-muted"><?php echo esc_html($item?$item->description:'Incomplete draft'); ?></span></td>
        <td data-label="Hirer"><strong><?php echo esc_html($invoice->contact_name); ?></strong><?php if($invoice->contact_organisation): ?><br><?php echo esc_html($invoice->contact_organisation); ?><?php endif; ?></td>
        <td data-label="Original booking"><?php if($context['url']): ?><a href="<?php echo esc_url($context['url']); ?>"><?php echo esc_html($context['label']); ?></a><?php else: echo esc_html($context['label']); endif; ?><br><small class="nms-muted"><?php echo esc_html($context['summary']); ?></small></td>
        <td data-label="Amount"><?php echo esc_html(MBS_Money::format($amount)); ?></td>
        <td data-label="Paid"><?php echo esc_html(MBS_Money::format((int)$invoice->paid_minor)); ?></td>
        <td data-label="Balance"><strong><?php echo esc_html($invoice->status==='draft'?'—':MBS_Money::format(MBS_Billing_Ledger::balance_minor($invoice))); ?></strong></td>
        <td data-label="Due"><?php echo esc_html(substr($invoice->due_at,0,10)); ?></td>
        <td data-label="Status"><span class="mbs-series-badge"><?php echo esc_html(self::status_label($invoice)); ?></span><?php if(self::is_archived($invoice)): ?><br><small>Archived</small><?php endif; ?></td>
        <td data-label="Email"><span title="Accepted means mail-server acceptance, not proof of inbox delivery."><?php echo esc_html($email_status); ?></span></td>
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
