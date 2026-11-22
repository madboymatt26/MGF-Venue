import {execFileSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
import assert from 'node:assert/strict';
const {chromium}=await import(process.env.MGF_BROWSER_MODULE||'/tmp/lamp/browser/node_modules/playwright/index.mjs');
const base=process.env.MGF_TEST_BASE||'http://localhost:8088';assert(['localhost','127.0.0.1'].includes(new URL(base).hostname));
function wp(args){return execFileSync(process.env.MGF_LOCAL_WP?'/tmp/lamp/bin/php':'docker',process.env.MGF_LOCAL_WP?['/tmp/lamp/wp.phar','--path='+process.env.MGF_LOCAL_WP,...args]:['compose','-f','tests/integration/docker-compose.yml','run','--rm','-T','cli','wp',...args,'--allow-root'],{encoding:'utf8',maxBuffer:4e6}).trim();}
const scenario='tests/integration/scenarios/';const evalFile=(name,...args)=>wp(['eval-file',(process.env.MGF_LOCAL_WP?'':'/workspace/')+scenario+name+'.php',...args]);
let browser,creditSeeded=false,termsSeeded=false,checks=0;const check=(ok,why)=>{assert(ok,why);checks++;};
mkdirSync('browser-artifacts',{recursive:true});
try{
 evalFile('credit-browser-fixture');creditSeeded=true;evalFile('invoice-terms-browser-fixture');termsSeeded=true;
 const urls=JSON.parse(wp(['option','get','mbs_terms_browser_fixture','--format=json']));
 browser=await chromium.launch({headless:true,executablePath:process.env.MGF_LOCAL_WP?'/usr/bin/google-chrome':undefined,args:['--no-sandbox']});
 const page=await browser.newPage();
 for(const width of [320,390,768,1280]){
  await page.setViewportSize({width,height:1000});
  for(const [kind,url]of Object.entries(urls)){
   assert.equal(new URL(url).hostname,new URL(base).hostname);await page.goto(url);
   const link=page.getByRole('link',{name:kind==='cancelled'?'Contact us to review this booking':'Request invoice terms',exact:true});
   check(await link.isVisible(),kind+' button visible at '+width);
   const href=await link.getAttribute('href');const parsed=new URL(href);check(parsed.protocol==='mailto:'&&parsed.pathname==='coordinator@example.invalid','Configured coordinator only');
   check(parsed.searchParams.get('subject').includes('BROW-CREDIT')&&parsed.searchParams.get('body').includes('BROW-CREDIT'),'Reference survives mailto decoding');
   check((await page.locator('body').innerText()).includes(kind==='cancelled'?'Staff must check availability':'existing payment deadlines still apply'),'Safe request/review wording');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No email horizontal overflow '+kind+' '+width);
   const box=await link.boundingBox();check(box.width<=width&&box.x>=0&&box.x+box.width<=width+1,'Button fits '+kind+' '+width);
   if([390,1280].includes(width))await page.screenshot({path:`browser-artifacts/invoice-terms-${kind}-${width}.png`,fullPage:true});
  }
 }
 await page.setViewportSize({width:390,height:1000});await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('integration-only');await page.locator('#wp-submit').click();await page.locator('#wpadminbar').waitFor();
 const credit=base+'/wp-admin/admin.php?page=mathlin-credit-accounts';await page.goto(credit);
 check(!await page.locator('[name=enabled]').isChecked(),'Feature remains staff controlled');
 const csrf=await page.request.post(base+'/wp-admin/admin-post.php',{form:{action:'mbs_credit_accounts',op:'enable',enabled:'1',_wpnonce:'invalid-synthetic-nonce'}});check(csrf.status()===403,'Invalid nonce cannot enable credit');
 await page.locator('[name=enabled]').check();await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Save feature preference'}).click()]);
 const create=page.locator('form').filter({has:page.locator('input[name=op][value=create]')});await create.locator('#credit-name').fill('Synthetic browser credit organisation');await create.locator('#credit-contacts').fill('browser-credit@example.invalid');await create.locator('#credit-days').fill('14');await create.locator('#credit-until').fill('2028-12-31');check(!await create.locator('[name=allow_unpaid_access]').isChecked(),'Unpaid access defaults off');await create.locator('[name=verified]').check();await Promise.all([page.waitForEvent('load'),create.getByRole('button').click()]);
 await page.goto(credit+'&scope_kind=booking&scope_ref=BROW-CREDIT');const assign=page.locator('form').filter({has:page.locator('input[name=op][value=assign]')});const choice=await assign.locator('select option').nth(1).getAttribute('value');await assign.locator('select').selectOption(choice);await assign.locator('[name=reason]').fill('Synthetic verified contact, authority and scope');await assign.locator('[name=verified]').check();const stale=await assign.evaluate(f=>Object.fromEntries(new FormData(f)));await Promise.all([page.waitForEvent('load'),assign.getByRole('button').click()]);const rejected=await page.request.post(base+'/wp-admin/admin-post.php',{form:stale});check(rejected.status()===409,'Stale assignment is rejected');
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=BROW-CREDIT');const panel=page.locator('details').filter({has:page.getByRole('heading',{name:'Invoice account / credit review'})});await panel.locator('summary').focus();await page.keyboard.press('Enter');check((await panel.innerText()).includes('Hirer requested invoice terms. This is not approval for credit.'),'Staff see request is not authority');check((await panel.innerText()).includes('Explicit invoice account assigned'),'Staff-reviewed assignment shown');await panel.scrollIntoViewIfNeeded();await page.screenshot({path:'browser-artifacts/invoice-terms-staff-review-390.png',fullPage:true});
 const state=JSON.parse(wp(['eval','$b=MBS_Bookings::get("BROW-CREDIT");echo wp_json_encode(array("status"=>$b->status,"paid"=>$b->amount_paid,"access"=>MBS_Access_Details::is_eligible_for_access($b)));']));check(state.status==='pending'&&Number(state.paid)===0&&!state.access,'Actual UI assignment does not approve booking, fake payment or release access');
 for(const width of [390,1280]){await page.setViewportSize({width,height:1000});await page.goto(credit);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Credit admin has no overflow '+width);await page.screenshot({path:`browser-artifacts/invoice-terms-accounts-${width}.png`,fullPage:true});}
 const reader=await browser.newPage();await reader.goto(base+'/wp-login.php');await reader.locator('#user_login').fill('expiry_reader');await reader.locator('#user_pass').fill('synthetic-reader-only');await reader.locator('#wp-submit').click();await reader.goto(credit);check(await reader.locator('[name=enabled]').count()===0,'Subscriber cannot manage credit');
 console.log('INVOICE_TERMS_BROWSER_OK: '+checks+' checks');
}finally{
 if(browser)await browser.close();
 if(termsSeeded)evalFile('invoice-terms-browser-fixture','cleanup');if(creditSeeded)evalFile('credit-browser-fixture','cleanup');
}
