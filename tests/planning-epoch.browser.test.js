import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

const fixture=JSON.parse(execFileSync('php',['tests/fixtures/browser-data.php'],{encoding:'utf8'}));
const html=execFileSync('php',['public/index.php'],{encoding:'utf8',env:{...process.env,IPDESIGNER_BASE_PATH:'/codex/ipDesigner'}});
const origin='http://ipdesigner.test/codex/ipDesigner';

async function setup(t) {
  const browser=await chromium.launch({headless:true});t.after(()=>browser.close());
  const page=await browser.newPage();page.setDefaultTimeout(10000);
  const state={epoch:'epoch-before',writes:[],errors:[]};page.on('pageerror',error=>state.errors.push(error.message));
  await page.route('**/*',async route=>{
    const path=new URL(route.request().url()).pathname.replace('/codex/ipDesigner','');
    if(path.startsWith('/api/')) {
      const headers={'X-Planning-Epoch':state.epoch};const resource=path.slice(5);
      if(route.request().method()!=='GET') {
        const epoch=route.request().headers()['x-planning-epoch'];state.writes.push(epoch);
        if(epoch!==state.epoch)return route.fulfill({status:409,headers,json:{error:{message:'Bitte Seite neu laden.'}}});
        return route.fulfill({headers,json:{data:{saved:true}}});
      }
      return route.fulfill({headers,json:{data:fixture[resource]??[]}});
    }
    if(path.startsWith('/assets/'))return route.fulfill({body:readFileSync('public'+path),contentType:path.endsWith('.css')?'text/css':'application/javascript'});
    return route.fulfill({body:html,contentType:'text/html'});
  });
  return {page,state};
}

async function editHost(page) {
  await page.locator('[data-action="host-edit"]').first().click();
  await page.locator('#modal [name="description"]').fill('Epoch-Test');
  await page.locator('#modal-submit').click();
}

test('Browser sendet die beim Laden gelesene Epoch bei Änderungen',async t=>{
  const {page,state}=await setup(t);await page.goto(origin+'/#hosts');
  await editHost(page);await page.locator('#modal').waitFor({state:'hidden'});
  assert.deepEqual(state.writes,['epoch-before']);assert.deepEqual(state.errors,[]);
});

test('Restore in anderem Tab sperrt alte Formulare und fordert dauerhaft Reload',async t=>{
  const {page,state}=await setup(t);await page.goto(origin+'/#hosts');
  await page.locator('[data-action="host-edit"]').first().waitFor();state.epoch='epoch-restored';
  await editHost(page);await page.locator('[data-planning-stale]').waitFor();
  assert.deepEqual(state.writes,['epoch-before']);
  await page.locator('#modal-submit').click();
  assert.deepEqual(state.writes,['epoch-before']);
  await page.locator('#modal .modal-cancel').first().click();
  await page.locator('[data-planning-stale] button').click();
  await page.locator('[data-action="host-edit"]').first().waitFor();
  assert.equal(await page.locator('[data-planning-stale]').count(),0);
  await editHost(page);await page.locator('#modal').waitFor({state:'hidden'});
  assert.deepEqual(state.writes,['epoch-before','epoch-restored']);assert.deepEqual(state.errors,[]);
});

test('Neue Epoch aus GET wird nicht stillschweigend in einen alten Plan übernommen',async t=>{
  const {page,state}=await setup(t);await page.goto(origin+'/#network-check');
  await page.locator('.check-finding').first().waitFor();state.epoch='epoch-restored';
  await page.locator('[data-action="network-check-refresh"]').click();
  await page.locator('[data-planning-stale]').waitFor();
  assert.equal(state.writes.length,0);assert.deepEqual(state.errors,[]);
});
