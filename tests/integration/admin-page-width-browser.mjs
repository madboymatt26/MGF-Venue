// Every MGF admin page uses the available desktop width, while readable
// form columns keep their measure. No form submissions or booking mutations.
// Mobile viewport is not real iOS Safari.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const base=process.env.MBS_LAYOUT_BASE||'http://localhost:8088';
assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const {chromium,webkit}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const engine=process.env.MBS_LAYOUT_ENGINE||'chromium';
const browser=await (engine==='webkit'?webkit:chromium).launch({headless:true,...(engine!=='webkit'&&process.env.MGF_BROWSER_EXECUTABLE?{executablePath:process.env.MGF_BROWSER_EXECUTABLE,args:['--no-sandbox']}:{})});
const mode=process.env.MBS_LAYOUT_MODE||'venue';
const page=await browser.newPage();const errors=[];let checks=0;
const check=(ok,msg)=>{checks++;assert.ok(ok,msg);};
const wanted=['mathlin-booking','mathlin-calendar','mathlin-settings','mathlin-archived','mathlin-blocked','mathlin-requests', 'mathlin-google-calendar','mathlin-audit-log','mathlin-analytics','mathlin-custom-fields','mathlin-add-booking','mathlin-emails'];
const readableForms={ 'mathlin-settings':true, 'mathlin-emails':true };
try{
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill(process.env.MBS_LAYOUT_PASSWORD||'integration-only');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 for(const slug of wanted){
  for(const width of [1920,1280,390]){
   await page.setViewportSize({width,height:900});
   await page.goto(base+'/wp-admin/admin.php?page='+slug);
   const host=page.locator('.mbs-admin').first();
   check(await host.count()===1,slug+' renders the admin shell ('+mode+')');
   const overflow=await page.evaluate(()=>{const doc=document.documentElement;if(doc.scrollWidth<=innerWidth+1)return null;const out=[];document.querySelectorAll('body *').forEach(el=>{const r=el.getBoundingClientRect();if(r.right>innerWidth+1&&r.width>1){const path=[];let n=el;while(n&&n!==document.body&&path.length<4){path.unshift(n.tagName.toLowerCase()+(n.id?'#'+n.id:'')+(n.className&&typeof n.className==='string'?'.'+n.className.trim().split(/\s+/).slice(0,2).join('.'):''));n=n.parentElement;}out.push(path.join('>')+' right='+Math.round(r.right)+' w='+Math.round(r.width));}});return{scrollWidth:doc.scrollWidth,innerWidth,offenders:out.slice(0,6)};});
   check(!overflow,slug+' no horizontal overflow at '+width+(overflow?' '+JSON.stringify(overflow):''));
   const m=await host.evaluate(el=>{const r=el.getBoundingClientRect();return{right:r.right,viewport:innerWidth};});
   if(width>1000){
    check(m.viewport-m.right<=30,slug+' fills available admin width at '+width+' ('+mode+', right gap '+Math.round(m.viewport-m.right)+'px)');
    if(readableForms[slug]){
     const inner=await page.locator('.nms-settings-layout').first().evaluate(el=>el.getBoundingClientRect().width);
     check(inner<=960,slug+' keeps a readable form column ('+Math.round(inner)+'px)');
    }
   }
  }
 }
 // The booking list table must stay reachable, not stretched into unusability.
 await page.setViewportSize({width:1920,height:900});
 await page.goto(base+'/wp-admin/admin.php?page=mathlin-booking');
 check(await page.locator('.nms-bookings-table').first().isVisible(),'Booking table visible at desktop width');
 check(await page.locator('.nms-bookings-table').first().evaluate(el=>{const host=el.closest('.mbs-admin').getBoundingClientRect(),r=el.getBoundingClientRect();return r.right<=host.right+1&&el.scrollWidth<=el.clientWidth+1;}),'Booking table fits the page width');
 assert.deepEqual(errors,[],'No page errors: '+errors.join('; '));
 console.log('ADMIN_PAGE_WIDTH_BROWSER_OK: '+checks+' checks, '+engine+', '+mode);
}finally{await browser.close();}
