/* Isolated development-browser acceptance. No form submissions or booking writes. */
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
const base=process.env.MBS_TEST_URL || 'http://127.0.0.1:8879';
assert(['127.0.0.1','localhost'].includes(new URL(base).hostname),'Disposable localhost required');
assert(process.env.MBS_TEST_PASSWORD,'Synthetic local password required');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.MBS_TEST_BROWSER_CHANNEL||undefined});
 let checks=0; const check=(ok,message)=>{checks++;assert(ok,message);};
 try{
  const page=await browser.newPage({viewport:{width:1920,height:1080}});
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(process.env.MBS_TEST_USER||'admin');await page.locator('#user_pass').fill(process.env.MBS_TEST_PASSWORD);
  await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
  const camp=process.env.MBS_TEST_CAMPSITE==='1';
  for(const width of [1920,1280,900,390]){
   await page.setViewportSize({width,height:1080});
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-add-booking');
   // Regression fixture: lower-specificity host styles must not narrow the cards
   // or spread the columns apart. This style exists only in the disposable page.
   await page.addStyleTag({content:'.mbs-entry-grid{gap:400px}.mbs-entry-grid .nms-card{max-width:450px}'});
   const metrics=await page.locator('.mbs-entry-grid').evaluate(el=>{
    const grid=el.getBoundingClientRect(),cards=[...el.children].map(c=>c.getBoundingClientRect());
    return{grid:{x:grid.x,width:grid.width},cards:cards.map(c=>({x:c.x,y:c.y,right:c.right,width:c.width,height:c.height})),overflow:document.documentElement.scrollWidth>innerWidth};
   });
   check(!metrics.overflow,'No Add booking page overflow at '+width);
   check(metrics.cards.length===2,'Exactly two booking cards');
   if(width>1000){check(Math.abs(metrics.cards[0].y-metrics.cards[1].y)<2,'Cards top-align');check(Math.abs(metrics.cards[1].x-metrics.cards[0].right-20)<2,'No oversized middle gap');check(Math.abs(metrics.cards[0].width-metrics.cards[1].width)<2,'Equal columns');}
   else check(metrics.cards[1].y>metrics.cards[0].y+metrics.cards[0].height,'Cards stack without stretching');
   check(await page.locator('.mbs-entry-grid>.nms-card>.nms-card-header').count()===2,'Themed booking headers');
   check(await page.locator('#entry-address').count()===(camp?0:1),'Profile-specific billing address');
   check(await page.locator('input[name="notify_hirer"]').isChecked()===false,'Customer mail remains opt-in');
   const heights=await page.locator('#entry-purpose,#entry-organisation,#entry-name,#entry-email,#entry-phone').evaluateAll(els=>els.map(el=>el.getBoundingClientRect().height));
   check(Math.max(...heights)-Math.min(...heights)<2,'Consistent text/email/telephone control heights');
   if(process.env.MBS_SCREENSHOT_DIR&&width===1280)await page.screenshot({path:path.join(process.env.MBS_SCREENSHOT_DIR,'add-booking-'+(camp?'campsite':'venue')+'.png'),fullPage:true});
   await page.goto(base+'/wp-admin/admin.php?page=mathlin-settings');
   const operational=page.locator('.nms-settings-layout>.nms-card').first();
   check(await operational.locator('>.mbs-card-body>form').count()===1,'Operational settings uses one padded form interior');
   check(await operational.locator('input[name="report_open_hour"]').inputValue()!==null,'Operational field retained');
   const formMetrics=await operational.evaluate(el=>{const r=el.getBoundingClientRect(),f=el.querySelector('form').getBoundingClientRect(),button=el.querySelector('button[type="submit"]').getBoundingClientRect();return{left:f.x-r.x,right:r.right-f.right,buttonLeft:button.x-f.x,overflow:el.scrollWidth>el.clientWidth};});
   check(formMetrics.left>=15&&formMetrics.right>=15&&Math.abs(formMetrics.left-formMetrics.right)<2,'Even operational form gutters');
   check(Math.abs(formMetrics.buttonLeft)<2,'Save button aligned with form');
   check(!formMetrics.overflow,'Operational card no horizontal bleed');
   check(await page.locator('#nms-spaces-table').evaluate(el=>getComputedStyle(el.parentElement).overflowX)==='auto','Space editor table remains horizontally reachable');
   check(await page.locator('.mbs-section-navigation').evaluate(el=>el.scrollWidth<=el.clientWidth),'Section navigation wraps');
   if(process.env.MBS_SCREENSHOT_DIR&&width===1280)await page.screenshot({path:path.join(process.env.MBS_SCREENSHOT_DIR,'settings-'+(camp?'campsite':'venue')+'.png')});
  }
  if(!camp){
   await page.setViewportSize({width:1280,height:1080});await page.goto(base+'/wp-admin/admin.php?page=mathlin-additional-charges');
   const extras=page.locator('details.mbs-extras-settings');check(await extras.count()===1,'Extras has themed disclosure');
   const summary=extras.locator('>summary');await summary.focus();await page.keyboard.press('Enter');check(await extras.getAttribute('open')!==null,'Native keyboard disclosure opens');
   check(await extras.locator('>.mbs-card-body>.mbs-table-scroll').count()===1,'Extras table is contained');
   check(await extras.locator('input[name="enabled"]').isChecked()===false,'Catalogue is not silently enabled');
   check(await summary.evaluate(el=>getComputedStyle(el).backgroundColor)==='rgb(116, 19, 220)','Purple disclosure header');
   if(process.env.MBS_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.MBS_SCREENSHOT_DIR,'extras-venue.png')});
   await page.keyboard.press('Enter');check(await extras.getAttribute('open')===null,'Native keyboard disclosure closes');
  }
  console.log('ADMIN_PANEL_LAYOUT_BROWSER_OK: '+checks+' checks, '+(camp?'campsite':'paid venue'));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
