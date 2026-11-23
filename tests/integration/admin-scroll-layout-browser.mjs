// Real disposable WordPress markup, plugin JS/CSS and WordPress toolbar.
// No form submissions or booking mutations. Mobile viewport is not real iOS Safari.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir} from 'node:fs/promises';
const base=process.env.MBS_LAYOUT_BASE||'http://localhost:8088';
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const {chromium,webkit}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const engine=process.env.MBS_LAYOUT_ENGINE||'chromium';
const browser=await (engine==='webkit'?webkit:chromium).launch({headless:true,...(engine!=='webkit'&&process.env.MGF_BROWSER_EXECUTABLE?{executablePath:process.env.MGF_BROWSER_EXECUTABLE,args:['--no-sandbox']}:{})});
const mode=process.env.MBS_LAYOUT_MODE||'venue',baseline=process.env.MBS_LAYOUT_BASELINE==='1';
const page=await browser.newPage();const errors=[],failures=[];let checks=0;
const check=(ok,msg)=>{checks++;if(!ok){failures.push(msg);if(!baseline)assert.ok(ok,msg);}};
const settle=()=>page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
const scroll=async y=>{await page.evaluate(y=>scrollTo(0,y),y);await settle();};
const detail=()=>page.goto(base+'/wp-admin/admin.php?page=mathlin-booking&action=view&ref=LAYOUT-01');
const screenshot=async name=>{await page.mouse.move(1,500);return page.screenshot({path:`browser-artifacts/layout-${baseline?'before':'after'}-${engine}-${mode}-${name}.png`});};
page.on('pageerror',e=>errors.push(e.message));
await mkdir('browser-artifacts',{recursive:true});
try{
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MBS_LAYOUT_PASSWORD||'integration-only');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 // Resize the SAME page, not just load a separate mock at each width.
 await detail();
 for(const width of [1920,1440,1280,1025,1024,900,782,600,390,320,1920]){
  await page.setViewportSize({width,height:900});await scroll(0);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal document overflow '+width);
  if(width>1024){
   const m=await page.locator('.mbs-single-page').evaluate(el=>{const r=el.getBoundingClientRect(),s=document.querySelector('.mbs-single-sidebar').getBoundingClientRect(),h=document.querySelector('.mbs-booking-hero').getBoundingClientRect();return{right:r.right,viewport:innerWidth,sidebar:s.width,hero:h.right};});
   check(m.viewport-m.right<=30,'Detail page fills available admin width '+width);
   check(Math.abs(m.hero-m.right)<=2,'Header shares page right edge '+width);
   check(Math.abs(m.sidebar-340)<=2,'Actions sidebar stays readable '+width);
   if(width===1920)await screenshot('desktop-1920');
  }else{
   const nav=page.locator('.mbs-mobile-jump-bar');
   check(await nav.isVisible(),'Section menu visible '+width);
   for(const y of [300,800,1600,800,300,0]){
    await scroll(y);
    const m=await nav.evaluate(el=>{const r=el.getBoundingClientRect(),a=document.getElementById('wpadminbar').getBoundingClientRect();return{top:r.top,height:r.height,toolbar:Math.max(0,a.bottom)};});
    if(y>=800)check(Math.abs(m.top-m.toolbar)<2,'Section menu docks to visible toolbar edge '+width+' y='+y);
    check(m.height<65,'Section menu remains one row '+width);
   }
   if(width===390){
    await scroll(600);await page.mouse.move(200,600);await page.mouse.wheel(0,700);await page.waitForFunction(()=>scrollY>900);await settle();
    check(Math.abs((await nav.boundingBox()).y)<2,'Wheel scrolling down keeps menu at top');
    const down=await page.evaluate(()=>scrollY);await page.mouse.wheel(0,-300);await page.waitForFunction(y=>scrollY<y,down);await settle();
    check(Math.abs((await nav.boundingBox()).y)<2,'Wheel scrolling up keeps menu at top');
   }
   // Every link can be brought into its horizontal scrollport and clicked.
   const links=nav.locator('a');
   for(let i=0;i<await links.count();i++){
    const link=links.nth(i),hash=await link.getAttribute('href');
    await page.locator(hash).evaluate(el=>{if(el.tagName==='DETAILS')el.open=false;});
    await link.scrollIntoViewIfNeeded();await link.click();await settle();
    const target=page.locator(hash),r=await target.boundingBox(),n=await nav.boundingBox();
    check(r.y>=n.y+n.height-2,'Jump target clears menu '+width+' '+hash);
    check(await target.evaluate(el=>el.tagName!=='DETAILS'||el.open),'Jump opens collapsed section '+hash);
   }
   if(width===390){
    await scroll(900);await page.mouse.move(1,500);
    check(await nav.locator('a').evaluateAll(els=>els.every(el=>!el.hasAttribute('aria-current')&&!el.classList.contains('is-active'))),'Remembered hash is not presented as an active section');
    await page.emulateMedia({media:'screen'});
    const focused=nav.locator('a').first();await focused.focus();
    check(await focused.evaluate(el=>getComputedStyle(el).outlineStyle!=='none'),'Keyboard focus remains clearly outlined');
    await screenshot('detail-mobile-scroll');
   }
  }
 }
 for(const width of [320,390,600,782,900,1280]){
  await page.setViewportSize({width,height:844});await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking');await settle();
  const bar=page.locator('.nms-bulk-bar'),boxes=page.locator('.nms-bulk-check');check(await boxes.count()>=8,'Seeded list renders '+width);
  for(const y of [1500,3000,1500]){await scroll(y);check(await bar.evaluate(el=>getComputedStyle(el).position!=='sticky'),'Unselected full bulk controls stay in flow '+width);}
  if(width===390)await screenshot('list-unselected');
  await boxes.first().check();await scroll(1600);
  check(await page.locator('#nms-bulk-count').innerText().then(t=>t.includes('1 selected')),'Selected count shown '+width);
  if(width<=782){
   const m=await bar.evaluate(el=>{const r=el.getBoundingClientRect();return{top:r.top,height:r.height,toolbar:Math.max(0,document.getElementById('wpadminbar').getBoundingClientRect().bottom)};});
   check(Math.abs(m.top-m.toolbar)<2,'Selected strip docks to visible edge '+width);
   check(m.height<=145,'Selected strip stays compact '+width);
   const selectedAction=page.locator('.nms-bookings-table .nms-action-btns .button').first();
   await selectedAction.evaluate(el=>el.scrollIntoView({block:'start'}));await settle();
   check(await selectedAction.evaluate(el=>{const r=el.getBoundingClientRect(),hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return hit===el||el.contains(hit);}), 'Selected strip does not block a focused booking action '+width);
   await scroll(1600);
   check(await page.locator('.nms-bulk-selectall').isHidden(),'Select-all removed only from selected strip '+width);
   if(width===390)await screenshot('list-selected');
   for(const y of [2800,1600]){await scroll(y);check(await bar.evaluate(el=>Math.abs(el.getBoundingClientRect().top-Math.max(0,document.getElementById('wpadminbar').getBoundingClientRect().bottom))<2),'Selected strip stable both directions '+width);}
   if(baseline)await boxes.first().uncheck();else await page.locator('#nms-bulk-clear').click();check(await page.locator('.nms-bulk-check:checked').count()===0,'Clear selection restores browsing '+width);
  }else await boxes.first().uncheck();
  if(width<=782&&!baseline){
   await page.locator('#nms-select-all-bar').check();await settle();
   const total=await boxes.count();check(await page.locator('.nms-bulk-check:checked').count()===total,'Select all still selects only individual bookings '+width);
   check(await page.locator('#nms-bulk-count').innerText().then(t=>t.includes(total+' selected')),'Multiple selected count '+width);
   await page.locator('#nms-bulk-action').selectOption('cancelled');check(await page.locator('#nms-bulk-count').innerText().then(t=>t.includes('can cancel')),'Eligible action count retained '+width);
   await page.locator('#nms-bulk-clear').click();check(await page.locator('.nms-bulk-check:checked').count()===0,'Clear all does not submit '+width);
   await page.locator('#nms-bulk-action').selectOption('');
   await boxes.first().check();await boxes.first().uncheck();check(!await bar.evaluate(el=>el.classList.contains('has-selection')),'Last checkbox uncheck exits selection '+width);
  }
  // All per-booking actions, and bottom utility actions, remain reachable.
  const actions=page.locator('.nms-bookings-table .nms-action-btns .button');
  for(let i=0;i<await actions.count();i++){const a=actions.nth(i);await a.scrollIntoViewIfNeeded();await settle();const r=await a.boundingBox();check(r&&r.x>=0&&r.x+r.width<=width+1&&r.y>=0&&r.y+r.height<=845,'Booking action reachable '+width+' #'+i+' '+JSON.stringify(r));}
  const exportLink=page.getByRole('link',{name:/Export CSV/});await exportLink.scrollIntoViewIfNeeded();check(await exportLink.isVisible(),'Bottom export reachable '+width);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'List no horizontal overflow '+width);
 }
 check(errors.length===0,'No JS errors '+JSON.stringify(errors));
 // Toolbar absent and short viewport, as on installations with hidden admin chrome.
 if(!baseline){
  await page.setViewportSize({width:390,height:600});await detail();
  await page.addStyleTag({content:'#wpadminbar{display:none!important}'});await scroll(1200);
  check(Math.abs((await page.locator('.mbs-mobile-jump-bar').boundingBox()).y)<2,'Hidden toolbar leaves no floating gap');
  await screenshot('short-viewport-hidden-toolbar');
 }
 console.log(JSON.stringify({mode,engine,baseline,checks,failures},null,2));
 if(failures.length&&!baseline)process.exitCode=1;
}finally{await browser.close();}
