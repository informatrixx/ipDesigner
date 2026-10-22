import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Real PHP-rendered shell and snapshot service output, isolated browser HTTP.
const fixture=JSON.parse(execFileSync('php',['tests/fixtures/browser-data.php'],{encoding:'utf8'}));
const snapshotFixture=JSON.parse(execFileSync('php',['tests/fixtures/browser-snapshots.php'],{encoding:'utf8'}));
const html=execFileSync('php',['public/index.php'],{encoding:'utf8',env:{...process.env,IPDESIGNER_BASE_PATH:'/codex/ipDesigner'}});
const origin='http://ipdesigner.test/codex/ipDesigner';
const saved=snapshotFixture.created;
const rescue=snapshotFixture.restored.rescue_snapshot;
const restoreNotice=`Planung wiederhergestellt. Rettungssnapshot: ${rescue.name} (${rescue.id}).`;

async function openFixture(t) {
  const browser=await chromium.launch({headless:true});t.after(()=>browser.close());
  const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(10000);
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  const status={requests:[],pageLoads:0,list:structuredClone(snapshotFixture.list),created:null,restored:null,restoreGate:null,restoreFail:false,requireErrors:false,failSnapshots:false,failNetworks:false,epoch:snapshotFixture.epoch};
  await page.route('**/*',async route=>{
    const path=new URL(route.request().url()).pathname.replace('/codex/ipDesigner','');
    if(path.startsWith('/api/')) {
      const resource=path.slice(5);status.requests.push(resource);
      const respond=(data,statusCode=200)=>route.fulfill({status:statusCode,headers:{'X-Planning-Epoch':status.epoch},json:statusCode===200?{data}:{error:{message:data}}});
      if(resource==='snapshots') {
        if(status.failSnapshots)return respond('Snapshot-Schema fehlt. Bitte php bin/migrate.php ausführen.',503);
        if(route.request().method()==='GET')return respond(status.requireErrors&&!status.restored?[snapshotFixture.error_preview.snapshot]:status.list);
        status.created=route.request().postDataJSON();const snapshot={...saved,...status.created,id:3};status.list.unshift(snapshot);return respond(snapshot);
      }
      if(/^snapshots\/\d+\/preview$/.test(resource)) {
        const result=structuredClone(status.requireErrors?snapshotFixture.error_preview:snapshotFixture.preview);
        return respond(result);
      }
      if(/^snapshots\/\d+\/restore$/.test(resource)) {
        status.restored=route.request().postDataJSON();if(status.restoreGate)await status.restoreGate;
        if(status.restoreFail)return respond('Planungsdaten wurden seit der Vorschau geändert. Erneut prüfen.',409);
        status.epoch=snapshotFixture.restored.epoch;status.list=structuredClone(snapshotFixture.after_list);return respond({...snapshotFixture.restored,snapshot_id:Number(resource.split('/')[1])});
      }
      if(resource==='networks'&&status.failNetworks)return respond('Normale Netzansicht defekt.',500);
      return respond(fixture[resource]??[]);
    }
    if(path.startsWith('/assets/'))return route.fulfill({body:readFileSync('public'+path),contentType:path.endsWith('.css')?'text/css':'application/javascript'});
    status.pageLoads++;return route.fulfill({body:html,contentType:'text/html'});
  });
  return {page,status,errors};
}

test('Snapshotdirektlink lädt keine normalen Planungsdaten; benannter Snapshot wird sicher angezeigt',async t=>{
  const {page,status,errors}=await openFixture(t);status.failNetworks=true;
  await page.goto(origin+'/#snapshots');await page.locator('.snapshot-list tbody tr strong').waitFor();
  assert.deepEqual(status.requests,['snapshots']);assert.equal(await page.locator('#global-search').isDisabled(),true);
  await page.locator('[data-snapshot-name]').fill('<img src=x>');await page.locator('[data-snapshot-description]').fill('Vor dem Umbau');
  await page.locator('[data-snapshot-create] button').click();
  await page.locator('.snapshot-status').filter({hasText:'Snapshot „<img src=x>“ erstellt.'}).waitFor();
  assert.deepEqual(status.created,{name:'<img src=x>',description:'Vor dem Umbau'});
  assert.equal(await page.locator('.snapshot-list img').count(),0);assert.equal(status.restored,null);assert.deepEqual(errors,[]);
});

test('Restore verlangt Vorschau und beide Bestätigungen bei Netzcheckfehlern und lädt vollständig neu',async t=>{
  const {page,status,errors}=await openFixture(t);status.requireErrors=true;
  await page.goto(origin+'/#snapshots');await page.locator('[data-snapshot-action="preview"]').click();
  await page.locator('.snapshot-preview').waitFor();assert.equal(status.restored,null);
  const restore=page.locator('[data-snapshot-action="restore"]');assert.equal(await restore.isDisabled(),true);
  await page.locator('[data-snapshot-confirm]').check();assert.equal(await restore.isDisabled(),true);
  await page.locator('[data-snapshot-confirm-errors]').check();assert.equal(await restore.isEnabled(),true);
  await page.locator('[data-snapshot-confirm]').uncheck();assert.equal(await restore.isDisabled(),true);
  await page.locator('[data-snapshot-confirm]').check();
  const navigation=page.waitForNavigation();await restore.click();await navigation;
  await page.locator('#notice .notice').filter({hasText:restoreNotice}).waitFor();
  await page.locator('.snapshot-kind').filter({hasText:'Rettungssnapshot'}).waitFor();
  assert.deepEqual(status.restored,{revision:snapshotFixture.error_preview.revision,plan_hash:snapshotFixture.error_preview.plan_hash,confirm_restore:true,confirm_errors:true});
  assert.equal(status.pageLoads,2);assert.equal(await page.locator('[data-planning-stale]').count(),0);
  await page.reload();await page.locator('.snapshot-list tbody tr strong').first().waitFor();
  assert.equal(await page.locator('#notice .notice').count(),0);assert.deepEqual(errors,[]);
});

test('Veraltete Restorevorschau wird entfernt; kein Reload bei Konflikt',async t=>{
  const {page,status,errors}=await openFixture(t);status.restoreFail=true;
  await page.goto(origin+'/#snapshots');await page.locator('[data-snapshot-action="preview"]').click();
  await page.locator('[data-snapshot-confirm]').check();await page.locator('[data-snapshot-action="restore"]').click();
  await page.locator('.snapshot-status.snapshot-error').filter({hasText:'Planungsdaten wurden seit der Vorschau geändert.'}).waitFor();
  assert.equal(await page.locator('.snapshot-preview').count(),0);assert.equal(status.pageLoads,1);assert.equal(status.restored.confirm_errors,false);assert.deepEqual(errors,[]);
});

test('Fehlende Snapshotmigration zeigt verständlichen Fehler und blockiert die normale App nicht',async t=>{
  const {page,status,errors}=await openFixture(t);status.failSnapshots=true;
  await page.goto(origin+'/#snapshots');await page.locator('.snapshot-status.snapshot-error').filter({hasText:'php bin/migrate.php'}).waitFor();
  assert.equal(await page.locator('[data-snapshot-create] button').isDisabled(),true);
  await page.locator('nav [data-view="dashboard"]').click();await page.locator('.check-summary').waitFor();
  assert.equal(await page.locator('#global-search').isEnabled(),true);
  status.failSnapshots=false;await page.locator('nav [data-view="snapshots"]').click();await page.locator('[data-snapshot-action="preview"]').waitFor();
  assert.equal(await page.locator('[data-snapshot-create] button').isEnabled(),true);assert.deepEqual(errors,[]);
});

test('Verzögerter Restorefehler bleibt nach Navigation als globale Meldung sichtbar',async t=>{
  const {page,status,errors}=await openFixture(t);status.restoreFail=true;
  let releaseRestore;status.restoreGate=new Promise(resolve=>{releaseRestore=resolve;});t.after(()=>releaseRestore());
  await page.goto(origin+'/#snapshots');await page.locator('[data-snapshot-action="preview"]').click();await page.locator('[data-snapshot-confirm]').check();
  const request=page.waitForRequest(request=>request.url().endsWith('/restore'));await page.locator('[data-snapshot-action="restore"]').click();await request;
  await page.locator('nav [data-view="hosts"]').click();await page.locator('.host-card').first().waitFor();releaseRestore();
  await page.locator('#notice .notice.error').filter({hasText:'Planungsdaten wurden seit der Vorschau geändert.'}).waitFor();
  assert.equal(status.pageLoads,1);assert.equal(await page.locator('.host-card').count(),fixture.hosts.length);assert.deepEqual(errors,[]);
});

test('Verzögerter Restoreerfolg lädt auch nach Navigation die gesamte aktuelle Ansicht neu',async t=>{
  const {page,status,errors}=await openFixture(t);
  let releaseRestore;status.restoreGate=new Promise(resolve=>{releaseRestore=resolve;});t.after(()=>releaseRestore());
  await page.goto(origin+'/#snapshots');await page.locator('[data-snapshot-action="preview"]').click();await page.locator('[data-snapshot-confirm]').check();
  const request=page.waitForRequest(request=>request.url().endsWith('/restore'));await page.locator('[data-snapshot-action="restore"]').click();await request;
  await page.locator('nav [data-view="hosts"]').click();await page.locator('.host-card').first().waitFor();
  assert.equal(status.requests.filter(resource=>resource==='hosts').length,1);
  const navigation=page.waitForNavigation();releaseRestore();await navigation;
  await page.locator('.host-card').first().waitFor();
  await page.locator('#notice .notice').filter({hasText:restoreNotice}).waitFor();
  assert.equal(status.pageLoads,2);assert.equal(status.requests.filter(resource=>resource==='hosts').length,2);
  assert.equal(await page.locator('[data-planning-stale]').count(),0);assert.deepEqual(errors,[]);
});
