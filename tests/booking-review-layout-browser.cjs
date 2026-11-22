/* Synthetic loopback WordPress only. Exercises native disclosures, editing and responsive forms. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
const {mkdirSync}=require('node:fs');
const base=process.env.MBS_TEST_URL||'http://127.0.0.1:8879';
assert(['127.0.0.1','localhost'].includes(new URL(base).hostname),'Disposable loopback required');
assert(process.env.MBS_TEST_PASSWORD,'Synthetic password required');
(async()=>{
 const browser=await chromium.launch({headless:true}); let checks=0;
 const check=(ok,why)=>{assert(ok,why);checks++;};
 mkdirSync('browser-artifacts',{recursive:true});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MBS_TEST_PASSWORD);
  await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
  for(const width of [320,390,768,1280]){
   await page.setViewportSize({width,height:1000});
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=BROW-HELD');
   const review=page.locator('#mbs-section-payment-review');
   check(await review.evaluate(el=>el.open),'Unresolved review opens by default '+width);
   check((await review.locator('>summary').innerText()).includes('Unresolved: £7.50'),'Collapsed summary carries unresolved amount '+width);
   const forms=review.locator('.mbs-money-form');check(await forms.count()===3,'Refund, retain and resolve remain separate forms');
   const fields=review.locator('.mbs-field input');
   for(let i=0;i<await fields.count();i++){
    const input=fields.nth(i);const m=await input.evaluate(el=>{const r=el.getBoundingClientRect(),l=el.parentElement.getBoundingClientRect();return{left:r.left,right:r.right,labelLeft:l.left,labelRight:l.right,display:getComputedStyle(el).display,height:r.height}});
    check(m.left>=0&&m.right<=width+1&&m.left>=m.labelLeft-1&&m.right<=m.labelRight+1,'Field contained '+width+' #'+i);
    check(m.display==='block','Field is below its label '+width+' #'+i);
   }
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No document overflow '+width);
   if(width===390||width===1280){await review.evaluate(el=>el.scrollIntoView({block:'start'}));await page.screenshot({path:'browser-artifacts/booking-money-'+width+'.png'});}
   const cards=page.locator('.mbs-single-grid details.nms-card');
   check(await cards.count()>=8,'Main page sections are minimisable '+width);
   for(let i=0;i<await cards.count();i++){
    const card=cards.nth(i), summary=card.locator('>summary');
    check(await summary.count()===1,'Card has a valid direct summary');
    await summary.focus();const before=await card.evaluate(el=>el.open);await page.keyboard.press('Enter');
    check(await card.evaluate(el=>el.open)!==before,'Keyboard toggle changes disclosure state '+i);
    await page.keyboard.press('Space');check(await card.evaluate(el=>el.open)===before,'Keyboard restores disclosure state '+i);
   }
   // Collapsing details must not break the hero's Edit booking action.
   await page.locator('#mbs-section-details >summary').click();
   await page.locator('#nms-toggle-edit').click();
   check(await page.locator('#mbs-section-details').evaluate(el=>el.open),'Edit opens minimised booking details');
   check(await page.locator('#nms-edit-name').isVisible(),'Edit fields reachable');
   await page.locator('#nms-cancel-edit').click();check(await page.locator('#nms-view-mode').isVisible(),'Cancel edit restores view');
   if(width===390){
    const notes=page.locator('details[aria-labelledby="mbs-admin-notes-heading"]');
    if(!await notes.evaluate(el=>el.open))await notes.locator('>summary').click();
    await page.locator('#nms-admin-notes').fill('Synthetic mobile note after disclosure toggle');
    await Promise.all([page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&r.request().postData()?.includes('mbs_save_admin_notes')),page.locator('#nms-save-notes').click()]);
    await page.reload();
    check(await page.locator('#nms-admin-notes').inputValue()==='Synthetic mobile note after disclosure toggle','Notes save survives reload');
   }
   // Native collapse never clears entered form data.
   const reason=review.locator('[name=reason]').first();await reason.fill('Unsaved synthetic review note');
   await review.locator('>summary').click();check(!await review.evaluate(el=>el.open),'Money review closes');
   check((await review.locator('>summary').innerText()).includes('Unresolved: £7.50'),'Unresolved amount visible while closed');
   await review.locator('>summary').click();check(await reason.inputValue()==='Unsaved synthetic review note','Unsaved fields survive minimise/expand');
   if(width<1024){
    await page.locator('#mbs-section-details >summary').click();
    await page.locator('.mbs-mobile-jump-bar a[href="#mbs-section-details"]').click();
    check(await page.locator('#mbs-section-details').evaluate(el=>el.open),'Details jump reveals minimised card');
    const audit=page.locator('#mbs-section-audit');
    if(await audit.count()){await audit.evaluate(el=>el.open=false);await page.locator('.mbs-mobile-jump-bar a[href="#mbs-section-audit"]').click();check(await audit.evaluate(el=>el.open),'Audit jump reveals history');}
   }
   if(width<1024){
    const links=page.locator('.mbs-mobile-jump-bar a');
    for(let i=0;i<await links.count();i++){
     const href=await links.nth(i).getAttribute('href');
     await links.nth(i).click();
     const bounds=await page.evaluate(h=>{
      const t=document.getElementById(h.slice(1));
      const head=t.tagName==='DETAILS'?t.querySelector(':scope >summary'):t.querySelector('details >summary');
      const bar=document.querySelector('.mbs-mobile-jump-bar').getBoundingClientRect();const r=head.getBoundingClientRect();
      return{top:r.top,bottom:r.bottom,barBottom:bar.bottom};
     },href);
     check(bounds.top>=bounds.barBottom-1&&bounds.bottom<=1000,'Jump header unobscured '+href+' at '+width+': '+JSON.stringify(bounds));
    }
    if(width===390){
     await review.evaluate(el=>el.scrollIntoView({block:'start'}));
     const m=await review.locator('>summary').boundingBox();const bar=await page.locator('.mbs-mobile-jump-bar').boundingBox();
     check(m.y>=bar.y+bar.height,'Money header clears sticky navigation');
     await page.screenshot({path:'browser-artifacts/booking-money-header-390.png'});
    }
   }
   // A compact page retains booking status and per-card status summaries.
   await cards.evaluateAll(els=>els.forEach(el=>el.open=false));
   check(await page.locator('.mbs-booking-hero .nms-status').isVisible(),'Booking status remains visible');
   check(await review.locator('>summary .mbs-card-badge').isVisible(),'Money status remains visible');
   if(width===390){await page.locator('.mbs-booking-hero').scrollIntoViewIfNeeded();await page.screenshot({path:'browser-artifacts/booking-minimised-390.png',fullPage:true});}
  }
  check(errors.length===0,'No JavaScript errors: '+errors.join(';'));
  console.log('BOOKING_REVIEW_LAYOUT_OK: '+checks+' checks across 320, 390, 768, 1280px');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
