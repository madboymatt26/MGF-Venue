/* Own disposable localhost only: never attach to a real customer browser. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
const base=process.env.MBS_TEST_URL||'http://127.0.0.1:8879';
assert.equal(new URL(base).hostname,'127.0.0.1');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});let checks=0;
 const check=(ok,msg)=>{checks++;assert(ok,msg)};
 try{
  const page=await browser.newPage({viewport:{width:1280,height:1000}});
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MBS_TEST_PASSWORD);
  await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
  const url=base+'/wp-admin/admin.php?page=mathlin-credit-accounts';
  await page.goto(url);check(await page.locator('[name=enabled]').count()===1,'Feature preference available');
  check(!await page.locator('[name=enabled]').isChecked(),'Feature starts off');
  const csrf=await page.request.post(base+'/wp-admin/admin-post.php',{form:{action:'mbs_credit_accounts',op:'enable',enabled:'1',_wpnonce:'invalid-synthetic-nonce'}});check(csrf.status()===403,'Invalid nonce rejected');
  await page.locator('[name=enabled]').check();await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Save feature preference'}).click()]);
  check(await page.locator('[name=enabled]').isChecked(),'Explicit feature preference saved');
  const create=page.locator('form').filter({has:page.locator('input[name=op][value=create]')});
  await create.locator('#credit-name').fill('Synthetic browser credit organisation');await create.locator('#credit-contacts').fill('browser-credit@example.invalid');await create.locator('#credit-days').fill('14');await create.locator('#credit-until').fill('2027-12-31');
  check(!await create.locator('[name=allow_unpaid_access]').isChecked(),'Unpaid access permission defaults off');
  await create.locator('[name=allow_unpaid_access]').check();await create.locator('[name=verified]').check();await Promise.all([page.waitForEvent('load'),create.getByRole('button').click()]);
  check((await page.locator('body').innerText()).includes('Synthetic browser credit organisation'),'Verified agreement visible');
  await page.goto(url+'&scope_kind=booking&scope_ref=BROW-CREDIT');
  const assign=page.locator('form').filter({has:page.locator('input[name=op][value=assign]')});
  const choice=await assign.locator('select option').nth(1).getAttribute('value');await assign.locator('select').selectOption(choice);await assign.locator('[name=reason]').fill('Synthetic browser contact and authority verified');await assign.locator('[name=verified]').check();
  const stale=await assign.evaluate(f=>Object.fromEntries(new FormData(f)));
  await Promise.all([page.waitForEvent('load'),assign.getByRole('button').click()]);
  const staleResult=await page.request.post(base+'/wp-admin/admin-post.php',{form:stale});check(staleResult.status()===409,'Stale assignment rejected');
  await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=BROW-CREDIT');
  const panel=page.locator('details').filter({has:page.getByRole('heading',{name:'Invoice account / credit review'})});
  check(await panel.count()===1,'Themed credit disclosure exists');
  await panel.locator('summary').focus();await page.keyboard.press('Enter');check(await panel.getAttribute('open')!==null,'Keyboard disclosure expands');
  check((await panel.innerText()).includes('Hirer requested invoice terms'),'Untrusted hirer request visible to staff');
  check((await panel.innerText()).includes('Explicit invoice account assigned'),'Explicit assignment shown without changing payment');
  for(const width of [1280,390]){await page.setViewportSize({width,height:1000});await page.goto(url);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No horizontal overflow '+width);if(process.env.MBS_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.MBS_SCREENSHOT_DIR,'credit-'+width+'.png'),fullPage:true});}
  const reader=await browser.newPage();await reader.goto(base+'/wp-login.php');await reader.locator('#user_login').fill('expiry_reader');await reader.locator('#user_pass').fill(process.env.MBS_TEST_READER_PASSWORD);await Promise.all([reader.waitForEvent('load'),reader.locator('#wp-submit').click()]);await reader.goto(url);check(await reader.locator('[name=enabled]').count()===0,'Subscriber cannot manage credit');
  console.log('CREDIT_BROWSER_OK: '+checks+' checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
