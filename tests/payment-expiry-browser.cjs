/* Disposable local WordPress browser acceptance. Never use on a real site. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
const base=process.env.MBS_TEST_URL||'http://127.0.0.1:8879';
assert.equal(new URL(base).hostname,'127.0.0.1');
assert(process.env.MBS_TEST_PASSWORD,'Synthetic password required');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 let checks=0; const check=(ok,msg)=>{checks++;assert(ok,msg)};
 try {
  const page=await browser.newPage({viewport:{width:1280,height:1000}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',async r=>{if(r.url().includes('admin-ajax.php')&&r.status()>=400)console.log('AJAX',r.status(),await r.text());});
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MBS_TEST_PASSWORD);
  await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
  if(process.env.MBS_TEST_FREE==='1'){
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-settings');check(await page.getByRole('link',{name:'Unpaid-booking cancellation policy and money-held reviews'}).count()===0,'Free-site policy link absent');
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-payment-expiry');check(await page.locator('input[name="enabled"]').count()===0,'Direct free-site policy controls absent');
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=BROW-PAST');check(await page.locator('#mbs-expiry-short-ack').count()===0,'Free-site approval has no paid policy');
   console.log('PAYMENT_EXPIRY_FREE_BROWSER_OK: '+checks+' checks');return;
  }
  const csrf=await page.request.post(base+'/wp-admin/admin-post.php',{form:{action:'mbs_payment_expiry',operation:'settings',enabled:'1',_wpnonce:'invalid-synthetic-nonce'}});check(csrf.status()===403,'Invalid nonce rejected');
  const reader=await browser.newPage();await reader.goto(base+'/wp-login.php');await reader.locator('#user_login').fill('expiry_reader');await reader.locator('#user_pass').fill(process.env.MBS_TEST_READER_PASSWORD);await Promise.all([reader.waitForEvent('load'),reader.locator('#wp-submit').click()]);await reader.goto(base+'/wp-admin/admin.php?page=mathlin-payment-expiry');check(!(await reader.locator('input[name="enabled"]').count()),'Subscriber cannot configure policy');await reader.close();
  const view=async ref=>{await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref='+ref);};
  await view('BROW-SHORT');
  const panel=()=>page.locator('section').filter({hasText:'Payment deadlines and money review'});
  check(await panel().count()===1,'Policy panel visible');
  check((await panel().innerText()).includes('full payment is required'),'Short deadline explained');
  check(await page.locator('#mbs-expiry-short-ack').count()===1,'Explicit short acknowledgement');
  await page.locator('#mbs-expiry-short-ack').focus();await page.keyboard.press('Space');check(await page.locator('#mbs-expiry-short-ack').isChecked(),'Keyboard acknowledgement');
  await page.locator('#mbs-expiry-short-ack').uncheck();
  let dialogs=[]; page.on('dialog',async d=>{dialogs.push(d.message());await d.accept();});
  await Promise.all([page.waitForResponse(r=>r.url().includes('admin-ajax.php')),page.locator('.nms-btn-confirm').click()]);await page.waitForTimeout(300);
  check(dialogs.some(x=>/acknowledge/i.test(x)),'Unacknowledged approval rejected: '+dialogs.join(';'));
  check(await page.locator('.nms-btn-confirm').count()===1,'Rejected hire remains pending');
  await page.locator('#mbs-expiry-short-ack').check();
  await Promise.all([page.waitForEvent('load'),page.locator('.nms-btn-confirm').click()]);
  check((await panel().innerText()).includes('Status: active'),'Acknowledged approval enrolled');
  check((await panel().innerText()).includes('08:00'),'Frozen exact cutoff visible');
  await view('BROW-NORMAL');await Promise.all([page.waitForEvent('load'),page.locator('.nms-btn-confirm').click()]);
  check((await panel().innerText()).includes('Status: active'),'Normal approval enrolled');
  check((await panel().innerText()).includes('deposit'),'Deposit deadline visible');
  await view('BROW-PAST');check(await page.locator('#mbs-expiry-manual-reason').count()===1,'After-cutoff manual reason required');
  await view('BROW-HELD');
  check((await panel().innerText()).includes('Unresolved: £7.50'),'Held deposit explicit');
  check(await panel().getByRole('link',{name:/Review\/refund original order/}).count()===1,'Original native refund link');
  const stale=await panel().locator('form').first().evaluate(f=>Object.fromEntries(new FormData(f)));
  check((await panel().innerText()).includes('does not send money'),'Bank recording is not transfer');
  const refund=panel().locator('form').filter({has:page.locator('input[name="operation"][value="bank_refund"]')});
  await refund.locator('[name="amount"]').fill('4.00');await refund.locator('[name="date"]').fill('2026-11-05');await refund.locator('[name="reference"]').fill('SYNTHETIC-REFUND-ONLY');await refund.locator('[name="reason"]').fill('Synthetic completed-transfer browser evidence');
  check(!(await refund.locator('[name="confirmed"]').isChecked()),'Completed transfer requires positive confirmation');
  await refund.locator('[name="confirmed"]').check();await Promise.all([page.waitForEvent('load'),refund.getByRole('button').click()]);
  check((await panel().innerText()).includes('Unresolved: £3.50'),'Partial bank refund leaves remainder');
  const staleResponse=await page.request.post(base+'/wp-admin/admin-post.php',{form:{...stale,operation:'retain',amount:'3.50',reason:'Stale synthetic decision'}});check((await staleResponse.text()).includes('Review changed'),'Stale decision rejected');
  const retain=panel().locator('form').filter({has:page.locator('input[name="operation"][value="retain"]')});
  await retain.locator('[name="amount"]').fill('3.50');await retain.locator('[name="reason"]').fill('Synthetic retention decision only');await Promise.all([page.waitForEvent('load'),retain.getByRole('button').click()]);
  check((await panel().innerText()).includes('Unresolved: £0.00'),'Retention reconciles remainder');
  const close=panel().locator('form').filter({has:page.locator('input[name="operation"][value="resolve"]')});await close.locator('[name="confirmed"]').check();await Promise.all([page.waitForEvent('load'),close.getByRole('button').click()]);
  check((await panel().innerText()).includes('Status: resolved'),'Explicit review closure');
  check((await panel().innerText()).includes('booking status: cancelled'),'Refund never reopens');
  for(const width of [1280,390]){await page.setViewportSize({width,height:1000});await view('BROW-SHORT');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No page overflow '+width);if(process.env.MBS_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.MBS_SCREENSHOT_DIR,'expiry-'+width+'.png'),fullPage:true});}
  check(errors.length===0,'No JavaScript errors: '+errors.join(';'));
  console.log('PAYMENT_EXPIRY_BROWSER_OK: '+checks+' checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
