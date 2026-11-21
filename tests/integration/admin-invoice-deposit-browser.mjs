import {execFileSync,execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {mkdirSync} from 'node:fs';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.MGF_BROWSER_MODULE || '/tmp/lamp/browser/node_modules/playwright/index.mjs');
function wp(args){
 const base=process.env.MGF_LOCAL_WP;
 return execFileSync(base ? '/tmp/lamp/bin/php' : 'docker',base?['/tmp/lamp/wp.phar','--path='+base,...args]:['compose','-f','tests/integration/docker-compose.yml','run','--rm','-T','cli','wp',...args,'--allow-root'],{encoding:'utf8',maxBuffer:4e6}).trim();
}
const f=JSON.parse(wp(['option','get','mbs_journey_fixture','--format=json']));
const base=process.env.MGF_TEST_BASE || 'http://localhost:8088';
assert(['localhost','127.0.0.1'].includes(new URL(base).hostname),'Loopback only');
let checks=0;const check=(condition,label)=>{assert(condition,label);checks++;};
const state=()=>JSON.parse(wp(['eval','$f=get_option("mbs_journey_fixture");global $wpdb;$rows=array();foreach($f["refs"] as $k=>$ref){$b=MBS_Bookings::get($ref);$rows[$k]=array("status"=>$b->status,"paid"=>$b->amount_paid,"deposit"=>$b->deposit_paid);} $i=MBS_Billing_Ledger::get_invoice($f["invoice_ref"]);echo wp_json_encode(array("bookings"=>$rows,"paid"=>(int)$i->paid_minor,"balance"=>MBS_Billing_Ledger::balance_minor($i),"version"=>(int)$i->version,"transactions"=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".$wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE." WHERE invoice_id=%d",$i->id)),"mail"=>get_option("mbs_journey_test_mail",array())));']));
mkdirSync('browser-artifacts',{recursive:true});
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
try{
 for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:900}});const page=await context.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('integration-only');await page.locator('#wp-submit').click({noWaitAfter:true});await page.locator('#wpadminbar').waitFor();
  const before=state();
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking');
  const link=page.locator('.mbs-attention__item').filter({hasText:f.invoice_ref}).locator('a');
  // Existing synthetic rows can put the relevant item behind Show more.
  if(!await link.isVisible()){const details=page.locator('.mbs-attention__more');if(await details.count())await details.locator('summary').click();}
  check(await link.isVisible(),'Attention link visible');
  await Promise.all([page.waitForURL(u=>u.searchParams.get('page')==='mathlin-invoice',{waitUntil:'domcontentloaded'}),link.click()]);
  check(new URL(page.url()).searchParams.get('ref')===f.invoice_ref,'Click follows exact invoice');
  await page.locator('h1').filter({hasText:'Invoice review'}).waitFor();
  check(await page.locator('[data-balance-label="Outstanding"]').textContent()==='£'+(before.balance/100).toFixed(2),'Visible balance equals DB');
  check((await page.locator('body').textContent()).includes('Synthetic initial payment'),'Payment history visible');
  check((await page.locator('body').textContent()).includes(f.refs.LEDGER),'Booking link visible');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Review has no document overflow');
  const opened=state();check(opened.paid===before.paid&&opened.transactions===before.transactions&&opened.mail.length===before.mail.length,'Opening review leaves money and mail unchanged');
  const amountCells=page.locator('.mbs-review-table td[data-label="Amount"]');
  for(let n=0;n<await amountCells.count();n++){const box=await amountCells.nth(n).boundingBox();check(box.x>=0&&box.x+box.width<=width+1,'Payment amounts visible without horizontal scroll');}
  await page.screenshot({path:`browser-artifacts/invoice-review-${width}.png`,fullPage:true});
  await page.waitForFunction(()=>window.jQuery && (jQuery._data(document,'events')?.click || []).some(e=>e.selector==='.mbs-view-invoice-btn'));
  const docResponse=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&r.request().postData()?.includes('mbs_invoice_document'));
  await page.getByRole('button',{name:'Open issued invoice',exact:true}).click();
  await docResponse;await page.locator('#mbs-invoice-modal .mbs-modal__body .mbs-modal__loading').waitFor({state:'detached'});
  check((await page.locator('#mbs-invoice-modal .mbs-modal__body').textContent()).includes(f.invoice_ref),'Issued invoice viewer renders the selected invoice');
  const pdf=await context.request.get(await page.locator('#mbs-invoice-modal .mbs-modal__download').getAttribute('href'));
  check(pdf.ok()&&(await pdf.body()).subarray(0,4).toString()==='%PDF','Actual PDF download renders');
  await page.locator('#mbs-invoice-modal .mbs-modal__close').click();
  await page.locator('.mbs-review-table a').filter({hasText:f.refs.LEDGER}).click();
  check(new URL(page.url()).searchParams.get('ref')===f.refs.LEDGER,'Related booking link opens correct booking');
  await page.goBack();
  // Real partial payment form. A client confirmation describes the external email effect.
  await page.getByText('Record a received bank-transfer payment',{exact:true}).click();
  await page.locator('.nms-manual-invoice-payment [name=amount]').fill('5.00');
  await page.locator('.nms-manual-invoice-payment [name=note]').fill('Synthetic browser '+width);
  page.once('dialog',d=>d.accept());
  const reqPromise=page.waitForRequest(r=>r.url().includes('admin-ajax.php')&&(r.postData()||'').includes('mbs_record_invoice_manual_payment'));
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.nms-manual-invoice-payment button[type=submit]').click()]);
  const req=await reqPromise;const after=state();
  check(after.mail.length===before.mail.length+1,'One invoice-payment receipt only');
  check(after.paid===before.paid+500&&after.balance===before.balance-500&&after.transactions===before.transactions+1,'Real payment updates ledger once');
  check((await page.locator('body').textContent()).includes('Synthetic browser '+width),'New payment visible after reload');
  const replay=await context.request.post(req.url(),{headers:{'Content-Type':'application/x-www-form-urlencoded'},data:req.postData()});
  check((await replay.json()).success,'Same request replay accepted idempotently');
  const repeated=state();check(repeated.paid===after.paid&&repeated.transactions===after.transactions&&repeated.mail.length===after.mail.length,'Replay does not duplicate payment or receipt');
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-invoice&ref=MISSING');check((await page.locator('body').textContent()).includes('Invoice not found'),'Missing review state');
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-invoice');check((await page.locator('body').textContent()).includes('Open an invoice from Needs attention'),'Empty review state');
  // Filter preserves the fixture list and keeps maintenance controls at the bottom.
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&s='+f.tag);
  const footer=page.locator('.mbs-list-tools');const table=page.locator('.nms-bookings-table');
  check((await footer.boundingBox()).y>(await table.boundingBox()).y+(await table.boundingBox()).height-1,'Export and archive below list');
  check((await footer.locator('a').getAttribute('href')).includes('search='+f.tag),'Export search retained');
  await page.locator('#nms-bulk-action').selectOption('deposit_paid');await page.locator('#nms-bulk-apply').click();
  check((await page.locator('#nms-bulk-msg').textContent()).includes('at least one'),'Empty selection refused in UI');
  const key=width===1280?'DESKTOP':'MOBILE';
  await page.locator('.nms-bulk-check[value="'+f.refs[key]+'"]').check();
  await page.locator('.nms-bulk-check[value="'+f.refs.PARTIAL+'"]').check();
  await page.locator('#nms-bulk-action').selectOption('deposit_paid');
  let dialog='';page.once('dialog',d=>{dialog=d.message();d.accept();});
  const bulkReqPromise=page.waitForRequest(r=>r.url().includes('admin-ajax.php')&&(r.postData()||'').includes('mbs_bulk_action'));
  await page.locator('#nms-bulk-apply').click();await page.locator('#nms-bulk-msg').filter({hasText:'1 processed, 1 skipped'}).waitFor();
  check(dialog.includes('not its full balance')&&dialog.includes('emails a deposit receipt'),'Deposit confirmation describes amount and email');
  const bulkReq=await bulkReqPromise;const deposited=state();
  check(deposited.mail.length===repeated.mail.length+1,'Only eligible deposit emails one receipt');
  check(deposited.bookings[key].status==='deposit_paid'&&Number(deposited.bookings[key].paid)===25&&Number(deposited.bookings[key].deposit)===25,'UI deposit changes correct booking and amount');
  check(Number(deposited.bookings.PARTIAL.paid)===12,'Mixed selection preserves prior partial money');
  check(deposited.paid===repeated.paid&&deposited.transactions===repeated.transactions,'Legacy deposit does not fabricate invoice ledger entries');
  await context.request.post(bulkReq.url(),{headers:{'Content-Type':'application/x-www-form-urlencoded'},data:bulkReq.postData()});
  check(state().mail.length===deposited.mail.length,'Repeat bulk sends no extra email');
  await page.reload();
  check(await page.locator('.nms-bulk-check[value="'+f.refs[key]+'"]').getAttribute('data-status')==='deposit_paid','Resulting status rendered');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Booking list has no document overflow');
  await page.locator('.nms-bulk-bar').scrollIntoViewIfNeeded();await page.screenshot({path:`browser-artifacts/deposit-result-${width}.png`,fullPage:true});
  await footer.scrollIntoViewIfNeeded();await page.screenshot({path:`browser-artifacts/booking-tools-bottom-${width}.png`});
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&s=NO-MATCH-SYNTHETIC');check(await page.locator('.nms-empty').count()===1&&await footer.count()===1,'Empty list retains bottom tools');
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&s='+f.tag+'-PAGEBOOK&status=confirmed');
  check(await page.locator('.nms-bulk-check').count()===50,'Paginated booking page has 50 rows');
  await page.getByRole('link',{name:'Next page',exact:true}).click();
  check(await page.locator('.nms-bulk-check').count()===2,'Next booking page preserves filtered remainder');
  check((await footer.boundingBox()).y>(await table.boundingBox()).y+(await table.boundingBox()).height-1,'Bottom tools remain below paginated list');
  check((await footer.locator('a').getAttribute('href')).includes('status=confirmed'),'Export status filter retained');
  let archiveDialog='';page.once('dialog',d=>{archiveDialog=d.message();d.dismiss();});await page.locator('#nms-archive-past').click();
  check(archiveDialog.includes('Archive all past bookings'),'Moved archive button retains confirmation without executing');
  check(errors.length===0,'No browser JS errors: '+errors.join(';'));
  const nonce=await page.evaluate(()=>MBS_Admin.nonce);
  // Both requests are in flight together. The shared lock/CAS must permit just one.
  if(width===1280){
   const raceBefore=state();const results=await Promise.all([0,1].map(()=>context.request.post(base+'/wp-admin/admin-ajax.php',{form:{action:'mbs_bulk_action',nonce,bulk_action:'deposit_paid','refs[]':f.refs.RACE}})));
   const bodies=await Promise.all(results.map(r=>r.json()));
   check(bodies.reduce((n,r)=>n+(r.data?.processed||0),0)===1,'Concurrent browser requests process once');
   const raceAfter=state();check(Number(raceAfter.bookings.RACE.paid)===25&&raceAfter.mail.length===raceBefore.mail.length+1,'Concurrent requests record one deposit and send one receipt');
  }
  await context.close();
 }
 // Separate PHP processes synchronised before invoking the authenticated endpoint.
 const processBefore=state();
 const workers=[1,2].map(n=>{
  const code=`global $wpdb;$f=get_option('mbs_journey_fixture');wp_set_current_user(1);$key='journey_barrier_'.$f['tag'];add_option($key.'_${n}',1);$deadline=microtime(true)+30;while((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->options.' WHERE option_name IN (%s,%s)',$key.'_1',$key.'_2'))<2){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timed out');usleep(20000);}$_POST=array('nonce'=>wp_create_nonce('mbs_admin_nonce'),'bulk_action'=>'deposit_paid','refs'=>array($f['refs']['PROCESSRACE']));$_REQUEST=$_POST;add_filter('wp_doing_ajax','__return_true');(new MBS_Admin())->ajax_bulk_action();`;
  const php=code;
  const args=process.env.MGF_LOCAL_WP?['/tmp/lamp/wp.phar','--path='+process.env.MGF_LOCAL_WP,'eval',php]:['compose','-f','tests/integration/docker-compose.yml','run','--rm','-T','cli','wp','eval',php,'--allow-root'];
  return promisify(execFile)(process.env.MGF_LOCAL_WP?'/tmp/lamp/bin/php':'docker',args,{maxBuffer:4e6});
 });
 const processResults=await Promise.all(workers);const results=processResults.map(r=>JSON.parse(r.stdout));
 check(results.reduce((n,r)=>n+(r.data?.processed||0),0)===1,'Separate synchronised PHP workers process one deposit');
 const processAfter=state();check(Number(processAfter.bookings.PROCESSRACE.paid)===25&&processAfter.mail.length===processBefore.mail.length+1,'Separate workers preserve one deposit and one receipt');
 const denied=await browser.newContext();const deniedPage=await denied.newPage();
 await deniedPage.goto(base+'/wp-login.php');await deniedPage.locator('#user_login').fill(f.tag+'sub');await deniedPage.locator('#user_pass').fill('integration-only');await deniedPage.locator('#wp-submit').click({noWaitAfter:true});await deniedPage.waitForURL(u=>!u.pathname.includes('wp-login.php'));
 const deniedResponse=await deniedPage.goto(base+'/wp-admin/admin.php?page=mathlin-invoice&ref='+f.invoice_ref);
 check(deniedResponse.status()===403&&!(await deniedPage.locator('body').textContent()).includes('journey@example.invalid'),'Subscriber route denies invoice/customer data');await denied.close();
 console.log(`PASS ${checks} real desktop/mobile invoice and deposit browser checks`);
}finally{await browser.close();}
