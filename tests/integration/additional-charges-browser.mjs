// Only the disposable local integration stack, using synthetic identities.
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {mkdir,readFile} from 'node:fs/promises';
const base='http://localhost:8088';
const fixture=JSON.parse(process.env.MGF_BROWSER_FIXTURE || '{}');
assert.ok(fixture.portal_url?.startsWith(base+'/'),'Refuse any non-local browser fixture');
const {chromium}=await import(pathToFileURL(process.env.MGF_BROWSER_MODULE).href);
const browser=await chromium.launch({headless:true});
await mkdir('browser-artifacts',{recursive:true});
const errors=[];
const watch=page=>{page.setDefaultTimeout(30000);page.setDefaultNavigationTimeout(45000);page.on('pageerror',error=>errors.push(error.message));};
async function login(page,user){
    await page.goto(base+'/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill('integration-only');
    await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
}
try {
    const admin=await browser.newContext(); const staff=await admin.newPage(); watch(staff);
    await login(staff,'admin');
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-additional-charges');
    console.log('BROWSER_STAGE: staff charges');
    await staff.locator('h1').filter({hasText:'Additional charges'}).waitFor();
    assert.equal(await staff.locator('input[name="amount"]').count(),0,'List has no create form');
    await staff.getByRole('columnheader',{name:'Original booking',exact:true}).waitFor();
    await staff.getByRole('link',{name:fixture.booking_ref,exact:true}).first().waitFor();
    await staff.getByRole('link',{name:fixture.invoice_ref,exact:true}).click();
    await staff.getByRole('link',{name:fixture.booking_ref,exact:true}).waitFor();
    await staff.getByRole('button',{name:'Confirm and send invoice now',exact:true}).waitFor();
    await staff.getByRole('button',{name:'Cancel charge — issue full credit note',exact:true}).waitFor();
    await staff.getByRole('link',{name:fixture.booking_ref,exact:true}).click();
    await staff.getByRole('heading',{name:'Additional charges',exact:true}).waitFor();
    await staff.getByRole('link',{name:fixture.invoice_ref,exact:true}).waitFor();
    await staff.getByRole('link',{name:'Add additional charge',exact:true}).click();
    await staff.getByLabel('Organisation (optional)').waitFor();
    assert.equal(await staff.getByLabel('Booking or series reference').inputValue(),fixture.booking_ref);
    await staff.screenshot({path:'browser-artifacts/charge-editor.png',fullPage:true});
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-additional-charges');
    await staff.locator('select[name="charge_status"]').selectOption('payment_review');
    await staff.getByRole('button',{name:'Filter',exact:true}).click();
    await staff.locator('h1').filter({hasText:'Additional charges'}).waitFor();
    await staff.screenshot({path:'browser-artifacts/staff-charges.png',fullPage:true});
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-additional-charges');
    await staff.getByRole('navigation',{name:'Charge archive views',exact:true}).getByRole('link',{name:'Archived',exact:true}).click();
    await staff.getByRole('link',{name:fixture.archive_ref,exact:true}).click();
    await staff.getByRole('button',{name:'Restore charge',exact:true}).waitFor();
    await staff.screenshot({path:'browser-artifacts/archived-charge.png',fullPage:true});
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-emails');
    await staff.locator('.mbs-tpl-subject[data-type="additional_charge_invoice"]').waitFor();
    await staff.locator('.mbs-tpl-body[data-type="additional_charge_credit"]').waitFor();
    // The new staff entry screen uses the same signed-in operator, real REST nonce
    // and disposable WordPress database. No customer notification is selected.
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-add-booking');
    console.log('BROWSER_STAGE: staff creation');
    await staff.locator('h1').filter({hasText:'Add booking'}).waitFor();
    await staff.locator('#entry-purpose').fill('Synthetic browser staff booking');
    await staff.locator('#entry-name').fill('Synthetic staff contact');
    await staff.locator('#entry-email').fill('staff-browser@example.invalid');
    await staff.locator('#entry-space').selectOption({index:1});
    await staff.locator('#entry-attendees').fill('8');
    const staffDate=new Date(Date.now()+260*86400000).toISOString().slice(0,10);
    await staff.locator('#entry-date').fill(staffDate);
    await staff.locator('#entry-start').fill('18:00');
    await staff.locator('#entry-end').fill('20:00');
    await staff.getByRole('button',{name:'Check dates and review',exact:true}).click();
    await staff.getByText('Review ready. Nothing has been created.',{exact:true}).waitFor();
    assert.equal(await staff.locator('#entry-save').isEnabled(),false,'Explicit review confirmation required');
    await staff.locator('#entry-confirm').check();
    await staff.screenshot({path:'browser-artifacts/staff-booking-review.png',fullPage:true});
    await staff.locator('#entry-save').click();
    await staff.getByText('Saved. Do not resubmit this reservation.',{exact:true}).waitFor();
    await staff.getByRole('link',{name:'View saved booking',exact:true}).click();
    await staff.getByText('Synthetic browser staff booking',{exact:true}).first().waitFor();
    // Admin month includes stays starting in the previous month. Each day is
    // a real keyboard-accessible link, and layout is checked at two widths.
    await staff.setViewportSize({width:1440,height:1000});
    await staff.goto(base+'/wp-admin/admin.php?page=mathlin-calendar&cal_year=2030&cal_month=12&cal_date=2030-12-01');
    await staff.getByText('INT-CAL-SPAN',{exact:false}).first().waitFor();
    for(const day of ['01','02'])assert.ok(await staff.locator('.nms-cal-day-link[href*="cal_date=2030-12-'+day+'"] .nms-cal-badge').count(),'Stay occupies December '+day);
    assert.equal(await staff.locator('.nms-cal-day-link[href*="cal_date=2030-12-03"] .nms-cal-badge').count(),0,'No spill beyond end date');
    const cell=await staff.locator('.nms-cal-day-link').first().boundingBox();
    assert.ok(cell.height>=110,'Comfortable full-height desktop day target');
    await staff.screenshot({path:'browser-artifacts/calendar-admin-desktop.png',fullPage:true});
    await staff.setViewportSize({width:390,height:844});
    assert.ok(await staff.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Admin calendar fits phone width');
    await staff.screenshot({path:'browser-artifacts/calendar-admin-mobile.png',fullPage:true});
    // Public views show tomorrow despite the real 14-day booking restriction.
    // This checks actual WordPress AJAX, not a mocked bookings response.
    const calendar=await admin.newPage();watch(calendar);
    async function selectCalendarDate(date,keyboard=false){
        const expected=new Date(date+'T00:00:00');
        const shown=await calendar.evaluate(()=>({year:new Date(NMS.today+'T00:00:00').getFullYear(),month:new Date(NMS.today+'T00:00:00').getMonth()}));
        let months=(expected.getFullYear()-shown.year)*12+expected.getMonth()-shown.month;
        while(months-->0){await calendar.locator('#nms-cal-next').click();await calendar.waitForFunction(()=>document.querySelector('#nms-cal-days').style.opacity==='1');}
        const day=calendar.locator('.nms-viewable-date[data-date="'+date+'"]');
        await day.waitFor();
        if(keyboard){await day.focus();await day.press('Enter');}else await day.click();
        await calendar.locator('#nms-cal-sidebar .nms-day-booking').first().waitFor();
    }
    await calendar.goto(fixture.calendar_url);
    console.log('BROWSER_STAGE: public calendar');
    await selectCalendarDate(fixture.near_date,true);
    await calendar.locator('#nms-cal-sidebar').getByText('Synthetic calendar near-date event',{exact:true}).waitFor();
    assert.equal(await calendar.locator('.nms-prefill-date').count(),0,'Readonly never offers booking');
    await calendar.screenshot({path:'browser-artifacts/calendar-public-near-date.png',fullPage:true});
    await calendar.goto(fixture.booking_url);
    await selectCalendarDate(fixture.near_date);
    assert.equal(await calendar.locator('.nms-prefill-date').count(),0,'Within notice date is viewable but not bookable');
    assert.equal(await calendar.locator('#nms-date').getAttribute('min'),await calendar.evaluate(()=>NMS.min_date),'Form notice remains enforced');
    const owner=await browser.newContext(); const portal=await owner.newPage(); watch(portal);
    await login(portal,'extra-browser'); await portal.goto(fixture.portal_url);
    console.log('BROWSER_STAGE: unverified owner');
    await portal.getByRole('heading',{name:'Verify your booking email',exact:true}).waitFor();
    assert.equal(await portal.getByText(fixture.invoice_ref,{exact:true}).count(),0,'Unverified account sees no private invoice');
    assert.ok(fixture.owner_proof.startsWith(base+'/'),'Local-only mailbox proof');
    await portal.goto(fixture.owner_proof);
    await portal.getByRole('button',{name:'Confirm email address',exact:true}).click();
    await portal.getByText('Email verified.',{exact:false}).first().waitFor();
    await portal.goto(fixture.portal_url);
    await portal.getByText(fixture.invoice_ref,{exact:true}).waitFor();
    const credit=portal.getByRole('link',{name:'Download credit note '+fixture.credit_ref,exact:true});
    await credit.waitFor();
    const pdf=await owner.request.get(await credit.getAttribute('href'));
    assert.equal(pdf.status(),200); assert.equal((await pdf.body()).subarray(0,5).toString(),'%PDF-');
    await portal.screenshot({path:'browser-artifacts/hirer-charges.png',fullPage:true});
    const guest=await browser.newContext(); const lookup=await guest.newPage(); watch(lookup);
    await lookup.goto(fixture.portal_url);
    console.log('BROWSER_STAGE: guest proof');
    await lookup.getByRole('button',{name:/Quick Lookup/}).click();
    await lookup.locator('#nms-lookup-ref').fill(fixture.booking_ref);
    await lookup.locator('#nms-lookup-email').fill('extra-charge@example.invalid');
    await lookup.getByRole('button',{name:'Look Up Booking',exact:true}).click();
    await lookup.getByText(/If the details match, we will email a secure access link/).waitFor();
    assert.equal(await lookup.getByRole('link',{name:'Download credit note '+fixture.credit_ref,exact:true}).count(),0,'Reference/email alone never returns private PDFs');
    assert.ok(fixture.guest_proof.startsWith(base+'/'),'Local-only guest proof');
    await lookup.goto(fixture.guest_proof);
    await lookup.getByRole('button',{name:'Confirm email address',exact:true}).click();
    await lookup.getByRole('button',{name:/Quick Lookup/}).click();
    await lookup.locator('#nms-lookup-ref').fill(fixture.booking_ref);
    await lookup.locator('#nms-lookup-email').fill('extra-charge@example.invalid');
    await lookup.getByRole('button',{name:'Look Up Booking',exact:true}).click();
    await lookup.getByRole('heading',{name:'Additional charges — separate from hire',exact:true}).waitFor();
    await lookup.getByRole('link',{name:'Download credit note '+fixture.credit_ref,exact:true}).waitFor();
    assert.ok(!await lookup.getByText('Internal notes',{exact:true}).count());
    await lookup.screenshot({path:'browser-artifacts/guest-charges.png',fullPage:true});
    assert.deepEqual(errors,[],'No browser JavaScript errors');
    console.log('ADDITIONAL_CHARGE_BROWSER_OK: staff filters/templates, owner credit PDF, guest charge/credit discovery');
} catch(error) {
    let index=0;
    for(const context of browser.contexts())for(const page of context.pages()){
        await page.screenshot({path:'browser-artifacts/failure-'+(++index)+'.png',fullPage:true}).catch(()=>{});
        console.error('Browser failure page:',page.url(),(await page.locator('body').innerText().catch(()=>'' )).slice(-8000));
    }
    throw error;
} finally {await browser.close();}
