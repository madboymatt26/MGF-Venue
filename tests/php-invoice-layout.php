<?php
/** Executable pricing-evidence and real renderer regression; no database or mail. */
define('ABSPATH', dirname(__DIR__).'/wp-plugin/mathlin-booking/');
define('MBS_PLUGIN_DIR', ABSPATH);
function get_option($key,$default=''){ $settings=array('mbs_spaces'=>array('Main Hall'=>array('rate_hourly'=>15,'rate_daily'=>100)), 'mbs_kitchen_price'=>20);return $settings[$key]??$default; }
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_bloginfo($key=''){return 'Example Scouts';}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function wp_date($f,$t=null){return date($f,$t??time());}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $code,$message;function __construct($c,$m){$this->code=$c;$this->message=$m;}function get_error_message(){return $this->message;}function get_error_code(){return $this->code;}}
require ABSPATH.'includes/class-money.php';
require ABSPATH.'includes/class-bookings.php';
require ABSPATH.'includes/invoice/class-issued-invoice-snapshot.php';
require ABSPATH.'includes/invoice/class-invoice-document-view-model.php';
require ABSPATH.'includes/invoice/class-html-renderer.php';
function verify($ok,$label){if(!$ok)throw new RuntimeException($label);}
$b=(object)array('space'=>'Main Hall','booking_date'=>'2026-10-31','booking_date_end'=>'2026-10-31','start_time'=>'10:00:00','end_time'=>'17:00:00','kitchen'=>1,'all_day'=>0,'scout_use'=>0,'pricing_tier'=>'standard','amount'=>'125.00','purpose'=>'Example event','custom_fields'=>'{}');
$update=MBS_Bookings::with_pricing_evidence($b,array());
$b=(object)array_merge((array)$b,$update);
$lines=MBS_Bookings::invoice_pricing_lines($b);
verify(count($lines)===2&&$lines[0]['quantity']===7&&$lines[0]['unit_amount_minor']===1500&&$lines[0]['amount_minor']===10500&&$lines[1]['amount_minor']===2000,'7 hours plus flat kitchen captured');
$changed=clone$b;$changed->start_time='11:00:00';
verify(MBS_Bookings::invoice_pricing_lines($changed)===array(),'stale evidence never itemises changed booking');
$legacy=clone$b;$legacy->custom_fields='{}';verify(MBS_Bookings::invoice_pricing_lines($legacy)===array(),'historical rate not guessed');
$s=new MBS_Issued_Invoice_Snapshot();$s->issuer_name='Example Scout Group';$s->issuer_address='Example Scout Centre';$s->issuer_email='bookings@example.test';$s->recipient_name='Example Hirer';$s->recipient_email='hirer@example.test';$s->recipient_address="Example Road\nExample Town";$s->invoice_number='INV-EXAMPLE-R3';$s->booking_ref='MBS-EXAMPLE';$s->issue_date='2026-10-27';$s->due_date='2026-10-27';$s->line_items=$lines;$s->subtotal_minor=12500;$s->total_minor=12500;$s->tax_label='Not registered for VAT';$s->payment_schedule=array('immediate'=>true);$s->account_at_issue=array('payments_received_minor'=>10500,'outstanding_minor'=>2000);$s->online_payment_instruction='Pay securely through your venue booking account.';
$roundtrip=MBS_Issued_Invoice_Snapshot::from_json($s->to_json());verify($roundtrip->account_at_issue===$s->account_at_issue,'issue-time payments immutable roundtrip');
$before=$s->to_json();$html=MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($s));
verify(strpos($html,'£15.00 per hour × 7 = £105.00')!==false,'hourly breakdown rendered');
verify(strpos($html,'£20.00</strong> due immediately')!==false&&strpos($html,'Full payment of')===false,'top-up not full invoice total');
verify($s->to_json()===$before,'rendering does not mutate snapshot');
verify(strpos($html,'display:flex')===false,'Dompdf-safe layout');
$s->account_at_issue=null;$old=MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($s));verify(strpos($old,'less payments already made')!==false,'legacy schedule does not claim unpaid full total');
$s->account_at_issue=array('payments_received_minor'=>12500,'outstanding_minor'=>0);verify(strpos(MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($s)),'No payment was outstanding')!==false,'paid invoice requests no second payment');
$s->account_at_issue=array('payments_received_minor'=>10500,'outstanding_minor'=>2000);
$s->document_type='credit_note';$credit_html=MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($s));verify(strpos($credit_html,'Payment Schedule')===false,'credit note must not request another payment');$s->document_type='invoice';
if(in_array('--render',$argv,true)){
    require ABSPATH.'vendor/autoload.php';
    $out=dirname(__DIR__).'/tmp/pdfs';if(!is_dir($out))mkdir($out,0700,true);
    foreach(array('single'=>2,'bank'=>2,'long'=>80)as$name=>$count){
        if($name==='bank'){$s->issuer_name='1st Example Market Scout Group';$s->issuer_address='Example Scouts, Example Street, Example Market, IP1 1AA';$s->issuer_charity_number='123456';$s->payment_method='offline_bacs';$s->bank_details=array('account_name'=>'Example Scout Group','sort_code'=>'00-00-00','account_number'=>'00000000');}
        if($name==='long'){$s->line_items=array_fill(0,$count,$lines[0]);$s->total_minor=$count*10500;$s->subtotal_minor=$s->total_minor;$s->account_at_issue=null;}
        $pdf=new Dompdf\Dompdf(array('isRemoteEnabled'=>false,'isPhpEnabled'=>false,'fontCache'=>$out,'tempDir'=>$out));$pdf->loadHtml(MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($s)));$pdf->setPaper('A4','portrait');$pdf->render();$pages=$pdf->getCanvas()->get_page_count();
        verify($name!=='long'?$pages===1:$pages>1,'actual Dompdf pagination '.$name);file_put_contents($out.'/invoice-'.$name.'.pdf',$pdf->output());echo $name.': '.$pages." pages\n";
    }
}
echo "OK: invoice pricing evidence, balance and renderer regression.\n";
