import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {mkdirSync} from 'node:fs';
const {chromium}=await import(process.env.MGF_BROWSER_MODULE||'/tmp/lamp/browser/node_modules/playwright/index.mjs');
const {default:ICAL}=await import(process.env.MGF_ICAL_MODULE||'/tmp/lamp/calendar/node_modules/ical.js/dist/ical.js');
function wp(args){return execFileSync(process.env.MGF_LOCAL_WP?'/tmp/lamp/bin/php':'docker',process.env.MGF_LOCAL_WP?['/tmp/lamp/wp.phar','--path='+process.env.MGF_LOCAL_WP,...args]:['compose','-f','tests/integration/docker-compose.yml','run','--rm','-T','cli','wp',...args,'--allow-root'],{encoding:'utf8',maxBuffer:4e6}).trim();}
const f=JSON.parse(wp(['option','get','mbs_calendar_subscription_fixture','--format=json']));
assert(['127.0.0.1','localhost'].includes(new URL(f.feed_url).hostname),'Loopback only');
let checks=0;function check(ok,label){assert(ok,label);checks++;}
const browser=await chromium.launch({args:['--no-sandbox']});const context=await browser.newContext();const api=context.request;
const parse=body=>new ICAL.Component(ICAL.parse(body)).getAllSubcomponents('vevent').map(c=>new ICAL.Event(c));
try{
 const r=await api.get(f.feed_url);const body=await r.text();
 check(r.ok()&&r.headers()['content-type'].startsWith('text/calendar'),'Actual HTTP calendar content type');
 check(body.startsWith('BEGIN:VCALENDAR\r\n')&&body.endsWith('END:VCALENDAR\r\n'),'Raw ICS, not JSON quoted or escaped');
 check(!body.replaceAll('\r\n','').includes('\n'),'CRLF lines only');
 check(body.split('\r\n').every(l=>Buffer.byteLength(l)<=75),'RFC octet folding');
 const events=parse(body);const own=events.filter(e=>e.summary.includes(f.tag));
 check(own.length===7,'Public recurring/single/overnight/all-day occupancy parsed');
 check(own.some(e=>e.summary==='Main Hall – '+f.tag+' public, event; Unicode café'),'Escaped commas, semicolons and Unicode parse correctly');
 check(own.find(e=>e.summary.includes('public,')).startDate.toString()==='2098-06-14T09:00:00Z','Summer local timezone converts to UTC');
 check(own.find(e=>e.summary.includes('winter')).startDate.toString()==='2098-01-14T10:00:00Z','Winter local timezone converts to UTC');
 check(own.filter(e=>e.summary.includes('repeat')).length===3,'Recurring dates expanded');
 const overnight=own.find(e=>e.summary.includes('overnight'));check(overnight.startDate.toString()==='2098-06-18T21:00:00Z'&&overnight.endDate.toString()==='2098-06-19T01:00:00Z','Overnight range preserved');
 check(own.find(e=>e.summary.includes('all day')).startDate.isDate,'All-day event remains date-only');
 check(!body.includes('PRIVATE-SUBSCRIPTION')&&!body.includes('secret-calendar@example.invalid')&&!body.includes('Hidden personal name')&&!body.includes('999.12')&&!body.includes(f.refs.PUBLIC),'No private purposes, notes, contacts, amounts or raw booking refs');
 const again=parse(await(await api.get(f.feed_url)).text());check(own.every(e=>again.some(a=>a.uid===e.uid)),'Stable UIDs on refreshed feed');
 const day=new URL(f.feed_url.replace('bookings/ical','bookings/date/2098-06-14'));
 const dayRows=await(await api.get(day.toString())).json();check(dayRows.some(b=>b.is_public&&b.purpose?.includes(f.tag))&&dayRows.some(b=>!b.is_public&&!b.purpose&&!b.name),'Public day view shares occupancy with private detail redaction');
 const download=new URL(f.feed_url);download.searchParams.set('download','1');const d=await api.get(download.toString());check(d.headers()['content-disposition'].startsWith('attachment')&&parse(await d.text()).length===events.length,'Snapshot downloads valid same-calendar ICS');
 const privateEntry=await api.get(f.feed_url.replace('bookings/ical','bookings/'+f.refs.PRIVATE+'/ical'));check(privateEntry.status()===403&&!(await privateEntry.text()).includes('PRIVATE-SUBSCRIPTION-PURPOSE'),'Individual private calendar still requires existing hirer authority');
 const head=await api.head(f.feed_url);check(head.ok()&&(await head.body()).length===0,'HEAD preserves headers without body');
 mkdirSync('browser-artifacts',{recursive:true});
 for(const width of [1280,390]){
  const c=await browser.newContext({viewport:{width,height:900},permissions:['clipboard-read','clipboard-write']});const page=await c.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(f.page_url);const panel=page.locator('.nms-calendar-subscribe');await panel.waitFor();await page.waitForFunction(()=>document.querySelectorAll('#nms-cal-days .nms-cal-day').length>=28);
  check(!(await page.locator('#nms-cal-month-label').textContent()).includes('Loading'),'Existing public calendar loads alongside subscription');
  check(await panel.getByRole('link',{name:'Subscribe to calendar',exact:true}).getAttribute('href')===f.feed_url.replace(/^https?:/,'webcal:'),'Subscription link targets exact feed without account/token');
  check(!await panel.locator('details').getAttribute('open'),'Instructions collapsed by default');
  const calendarBox=await page.locator('.nms-calendar-main').boundingBox();check(calendarBox.y<900-100,'Initial calendar remains readily visible');
  await page.screenshot({path:`browser-artifacts/calendar-default-${width}.png`,fullPage:true});
  await panel.locator('summary').click();await panel.getByRole('button',{name:'Copy subscription URL'}).click();await panel.getByRole('status').filter({hasText:'copied'}).waitFor();
  check(await page.evaluate(()=>navigator.clipboard.readText())===f.feed_url,'Actual clipboard receives feed URL');
  check((await panel.textContent()).includes('computer browser')&&(await panel.textContent()).includes('will not receive updates'),'Client limitations and snapshot warning visible');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No mobile/desktop horizontal overflow');
  await panel.scrollIntoViewIfNeeded();await page.screenshot({path:`browser-artifacts/calendar-subscription-${width}.png`,fullPage:true});
  check(errors.length===0,'No browser JS errors');await c.close();
 }
 const publicUid=own.find(e=>e.summary.includes('public,')).uid;
 wp(['eval',"$f=get_option('mbs_calendar_subscription_fixture');global $wpdb;$wpdb->update($wpdb->prefix.MBS_TABLE,array('status'=>'cancelled'),array('ref'=>$f['refs']['PUBLIC']));"]);
 check(!parse(await(await api.get(f.feed_url)).text()).some(e=>e.uid===publicUid),'Cancellation disappears on refreshed feed');
 wp(['eval',"$f=get_option('mbs_calendar_subscription_fixture');global $wpdb;$wpdb->update($wpdb->prefix.MBS_TABLE,array('booking_date_end'=>'2097-01-01'),array('ref'=>$f['refs']['WINTER']));"]);
 try{const broken=await api.get(f.feed_url);check(broken.status()===503&&(await broken.json()).code==='calendar_unavailable','Generation failures remain JSON errors, never a partial raw feed');}
 finally{wp(['eval',"$f=get_option('mbs_calendar_subscription_fixture');global $wpdb;$wpdb->update($wpdb->prefix.MBS_TABLE,array('booking_date_end'=>'2098-01-14'),array('ref'=>$f['refs']['WINTER']));"]);}
 wp(['option','update','mbs_campsite_mode','1']);
 const denied=await api.get(f.feed_url);check(denied.status()===403&&(await denied.json()).code==='campsite_access_denied','Campsite permission still denies unauthenticated subscriptions as JSON');
 const restricted=await context.newPage();await restricted.goto(f.page_url);check(await restricted.locator('.nms-calendar-subscribe').count()===0,'Campsite public page offers no unusable subscription');
 wp(['option','update','mbs_campsite_mode','0']);
 console.log(`PASS ${checks} calendar HTTP/parser/mobile-desktop subscription checks`);
}finally{wp(['option','update','mbs_campsite_mode','0']);await browser.close();}
