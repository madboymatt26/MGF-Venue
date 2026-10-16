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
const watch=page=>page.on('pageerror',error=>errors.push(error.message));
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
    const owner=await browser.newContext(); const portal=await owner.newPage(); watch(portal);
    await login(portal,'extra-browser'); await portal.goto(fixture.portal_url);
    await portal.getByText(fixture.invoice_ref,{exact:true}).waitFor();
    const credit=portal.getByRole('link',{name:'Download credit note '+fixture.credit_ref,exact:true});
    await credit.waitFor();
    const pdf=await owner.request.get(await credit.getAttribute('href'));
    assert.equal(pdf.status(),200); assert.equal((await pdf.body()).subarray(0,5).toString(),'%PDF-');
    await portal.screenshot({path:'browser-artifacts/hirer-charges.png',fullPage:true});
    const guest=await browser.newContext(); const lookup=await guest.newPage(); watch(lookup);
    await lookup.goto(fixture.portal_url);
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
