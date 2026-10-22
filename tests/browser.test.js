import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Real PHP-generated page and data, intercepted HTTP. No live DB or PHP server.
const fixture = JSON.parse(execFileSync('php',['tests/fixtures/browser-data.php'],{encoding:'utf8'}));
const csvFixture = JSON.parse(execFileSync('php',['tests/fixtures/browser-csv.php'],{encoding:'utf8'}));
const pageHtml = execFileSync('php',['public/index.php'],{encoding:'utf8',env:{...process.env,IPDESIGNER_BASE_PATH:'/codex/ipDesigner'}});
const origin = 'http://ipdesigner.test/codex/ipDesigner';

async function openFixture(t) {
  const browser = await chromium.launch({headless:true});
  t.after(()=>browser.close());
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  page.setDefaultTimeout(10000);
  const errors=[]; page.on('pageerror',error=>errors.push(error.message));
  const status = {checkRequests:0, fail:false, failNetworks:false, mutations:0,csvFail:false,csvBlocked:false,csvPreview:null,csvApply:null,csvExportIds:null,csvApplyGate:null,csvApplied:false,hostsAfterCsvApply:null,hostRequests:0};
  await page.route('**/*',async route=>{
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/codex/ipDesigner','');
    if(path.startsWith('/api/')) {
      const resource = path.slice(5);
      if (resource.startsWith('host-csv/')) {
        const action=resource.slice(9);
        if(action==='preview') {
          status.csvPreview=route.request().postDataJSON();
          const preview=structuredClone(csvFixture.preview);
          if(status.csvBlocked){preview.can_apply=false;preview.errors=[{record:2,message:'IP bereits vergeben.'}];}
          return route.fulfill({json:{data:preview}});
        }
        if(action==='apply') {
          status.csvApply=route.request().postDataJSON();
          if(status.csvApplyGate)await status.csvApplyGate;
          if(status.csvFail)return route.fulfill({status:409,json:{error:{message:'Planungsdaten wurden seit der Vorschau geändert. CSV erneut prüfen.'}}});
          status.mutations++;status.csvApplied=true;return route.fulfill({json:{data:csvFixture.applied}});
        }
        if(action==='export')status.csvExportIds=url.searchParams.get('host_ids');
        return route.fulfill({json:{data:csvFixture[action]}});
      }
      if(resource === 'network-check') status.checkRequests++;
      if(resource === 'hosts')status.hostRequests++;
      if ((resource === 'network-check' && status.fail) || (resource === 'networks' && status.failNetworks)) return route.fulfill({status:500,json:{error:{message:'Testfehler'}}});
      if (route.request().method() !== 'GET') {status.mutations++;return route.fulfill({json:{data:{saved:true}}});}
      const data=resource==='hosts'&&status.csvApplied&&status.hostsAfterCsvApply?status.hostsAfterCsvApply:fixture[resource]??[];
      return route.fulfill({json:{data}});
    }
    if(path.startsWith('/assets/')) return route.fulfill({body:readFileSync('public'+path),contentType:path.endsWith('.css')?'text/css':'application/javascript'});
    return route.fulfill({body:pageHtml,contentType:'text/html'});
  });
  return {page,status,errors};
}

test('Dashboard, Filter und Objektlinks funktionieren im nginx-Unterpfad', async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#dashboard');
  await page.locator('.check-summary').waitFor();
  assert.match(await page.locator('.check-count.check-error').textContent(),/1 Fehler/);
  await page.locator('nav [data-view="network-check"]').click();
  await page.locator('[data-check-filter="severity"]').selectOption('error');
  await page.waitForFunction(()=>document.querySelectorAll('.check-finding').length===1);
  await page.locator('[data-check-filter="site"]').selectOption(String(fixture.sites[1].id));
  await page.waitForFunction(()=>document.querySelector('.check-results').textContent.includes('Keine Befunde'));
  await page.locator('a', {hasText:'Filter zurücksetzen'}).click();
  await page.locator('.check-finding a[href*="network="]').first().click();
  await page.locator('.planner-layout').waitFor();
  assert.match(await page.locator('.planner-layout').textContent(),/LAN/);
  assert.equal(status.mutations,0); assert.deepEqual(errors,[]);
});

test('Topologiemarker öffnen Befunde; Zoom und Verschieben verändern keine Containergrößen', async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#topology');
  await page.locator('.check-marker').first().waitFor();
  const geometry=()=>page.locator('.site-region-frame').evaluateAll(nodes=>nodes.map(n=>['width','height'].map(a=>n.getAttribute(a))));
  const sizes=await geometry(); const count=status.checkRequests;
  await page.locator('[data-action="topology-zoom-in"]').click();
  await page.locator('[data-action="topology-zoom-out"]').click();
  assert.deepEqual(await geometry(),sizes);
  const emptySite=page.locator(`[data-site-region="${fixture.sites[1].id}"] .site-region-name`);
  const box=await emptySite.boundingBox();
  await page.mouse.move(box.x+20,box.y+4);await page.mouse.down();await page.mouse.move(box.x+45,box.y+24,{steps:4});await page.mouse.up();
  assert.deepEqual(await geometry(),sizes);
  assert.equal(status.checkRequests,count);
  const marker=page.locator('.check-marker').first();await marker.focus();await page.keyboard.press('Enter');
  await page.waitForURL(/#network-check\?entity=/);
  await page.locator('.check-finding').first().waitFor();
  assert.equal(status.mutations,0); assert.deepEqual(errors,[]);
});

test('Fehlgeschlagene Prüfung zeigt alte Ergebnisse deutlich als veraltet', async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#network-check');await page.locator('.check-finding').first().waitFor();
  status.fail=true;
  await page.locator('[data-action="network-check-refresh"]').click();
  await page.waitForFunction(()=>document.querySelector('.check-summary').textContent.includes('Prüfung fehlgeschlagen'));
  assert.match(await page.locator('.check-summary').textContent(),/letzter Stand/);
  status.fail=false;await page.locator('[data-action="network-check-refresh"]').click();
  await page.waitForFunction(()=>!document.querySelector('.check-summary').textContent.includes('fehlgeschlagen'));
  assert.deepEqual(errors,[]);
});

test('Direktlink zum Netzcheck funktioniert auch bei defekter normaler Netzansicht', async t=>{
  const {page,status,errors}=await openFixture(t);status.failNetworks=true;
  await page.goto(origin+'/#network-check');await page.locator('.check-finding').first().waitFor();
  assert.equal(status.checkRequests,1);assert.deepEqual(errors,[]);
});

test('Hostlink fokussiert die Zeile; fachliche Änderungen aktualisieren den Check', async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#network-check');await page.locator('.check-finding').first().waitFor();
  await page.locator('.check-finding a[href*="#hosts?"]').first().click();
  await page.locator('.host-card.focused').waitFor();
  const host=fixture.hosts.find(h=>h.name==='Server');
  await page.locator(`[data-action="host-edit"][data-id="${host.id}"]`).click();
  await page.locator('#modal [name="description"]').fill('Teständerung');
  await page.locator('#modal-submit').click();await page.locator('#modal').waitFor({state:'hidden'});
  const count=status.checkRequests;
  await page.locator('nav [data-view="network-check"]').click();
  await page.locator('.check-finding').first().waitFor();
  assert.ok(status.checkRequests>count);assert.equal(status.mutations,1);assert.deepEqual(errors,[]);
});

test('CSV-Datei zeigt Vorschau; erst ausdrückliche Bestätigung übernimmt und invalidiert Netzcheck',async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#dashboard');await page.locator('.check-summary').waitFor();
  const checks=status.checkRequests;
  await page.locator('nav [data-view="host-csv"]').click();
  await page.locator('.csv-editor').waitFor();
  assert.equal(await page.locator('.global-search input').isDisabled(),true);
  await page.locator('[data-csv-file]').setInputFiles({name:'hosts.csv',mimeType:'text/csv',buffer:Buffer.from(csvFixture.sample)});
  await page.getByText('Geladen: hosts.csv').waitFor();
  await page.locator('[data-csv-action="preview"]').click();
  await page.locator('.csv-preview').waitFor();
  assert.match(await page.locator('.csv-preview').textContent(),/10\.55\.0\.3/);
  assert.equal(status.mutations,0);assert.equal(status.checkRequests,checks);
  assert.equal(await page.locator('[data-csv-action="apply"]').isDisabled(),true);
  await page.locator('[data-csv-confirm]').check();
  await page.locator('[data-csv-action="apply"]').click();
  await page.getByText('CSV-Import vollständig gespeichert.').waitFor();
  assert.equal(status.mutations,1);assert.equal(status.csvApply.plan_hash,csvFixture.preview.plan_hash);
  assert.equal(status.csvApply.csv,status.csvPreview.csv);
  await page.locator('nav [data-view="dashboard"]').click();await page.locator('.check-summary').waitFor();
  assert.equal(await page.locator('.global-search input').isDisabled(),false);
  assert.ok(status.checkRequests>checks);assert.deepEqual(errors,[]);
});

test('CSV-Änderung verwirft die Vorschau; Konflikte und veraltete Daten werden sichtbar',async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#host-csv');await page.locator('[data-csv-text]').fill(csvFixture.sample);
  await page.locator('[data-csv-action="preview"]').click();await page.locator('.csv-preview').waitFor();
  await page.locator('[data-csv-updates]').check();assert.equal(await page.locator('.csv-preview').count(),0);
  status.csvBlocked=true;
  await page.locator('[data-csv-action="preview"]').click();await page.getByText('Datensatz 2: IP bereits vergeben.').waitFor();
  assert.equal(await page.locator('[data-csv-action="apply"]').count(),0);assert.equal(status.csvPreview.allow_updates,true);
  status.csvBlocked=false;status.csvFail=true;
  await page.locator('[data-csv-action="preview"]').click();await page.locator('[data-csv-confirm]').check();
  await page.locator('[data-csv-action="apply"]').click();
  await page.getByText('Planungsdaten wurden seit der Vorschau geändert. CSV erneut prüfen.').waitFor();
  assert.equal(await page.locator('.csv-preview').count(),0);assert.equal(status.mutations,0);assert.deepEqual(errors,[]);
});

test('CSV-Export aus der Hostansicht berücksichtigt den sichtbaren Filter',async t=>{
  const {page,status,errors}=await openFixture(t);
  await page.goto(origin+'/#hosts');await page.locator('#host-filter-text').fill('Server');
  await page.waitForFunction(()=>document.querySelectorAll('.host-card').length===1);
  const download=page.waitForEvent('download');await page.locator('[data-action="host-csv-export"]').click();
  assert.equal((await download).suggestedFilename(),'hosts.csv');
  assert.equal(status.csvExportIds,String(fixture.hosts.find(h=>h.name==='Server').id));
  assert.equal(status.mutations,0);assert.deepEqual(errors,[]);
});

test('CSV-Import meldet Erfolg nach Navigation und aktualisiert die sichtbare Hostliste',async t=>{
  const {page,status,errors}=await openFixture(t);
  let releaseApply;status.csvApplyGate=new Promise(resolve=>{releaseApply=resolve;});t.after(()=>releaseApply());
  status.hostsAfterCsvApply=[...fixture.hosts,{...fixture.hosts.find(host=>host.name==='Server'),id:999,name:'Import-Host',hostname:'imported',fqdn:'imported.example',interfaces:[]}];
  await page.goto(origin+'/#host-csv');await page.locator('[data-csv-text]').fill(csvFixture.sample);
  await page.locator('[data-csv-action="preview"]').click();await page.locator('[data-csv-confirm]').check();
  const applyRequest=page.waitForRequest(request=>request.url().endsWith('/api/host-csv/apply'));
  await page.locator('[data-csv-action="apply"]').click();await applyRequest;
  await page.locator('nav [data-view="hosts"]').click();await page.locator('.host-card').first().waitFor();
  assert.equal(await page.locator('.host-card').filter({hasText:'Import-Host'}).count(),0);
  releaseApply();
  await page.locator('#notice .notice').filter({hasText:'CSV-Import vollständig gespeichert.'}).waitFor();
  await page.locator('.host-card').filter({hasText:'Import-Host'}).waitFor();
  assert.equal(status.mutations,1);assert.equal(status.hostRequests,2);assert.deepEqual(errors,[]);
});

test('CSV-Import meldet einen verzögerten Konflikt auch nach Navigation sichtbar',async t=>{
  const {page,status,errors}=await openFixture(t);
  let releaseApply;status.csvApplyGate=new Promise(resolve=>{releaseApply=resolve;});t.after(()=>releaseApply());status.csvFail=true;
  await page.goto(origin+'/#host-csv');await page.locator('[data-csv-text]').fill(csvFixture.sample);
  await page.locator('[data-csv-action="preview"]').click();await page.locator('[data-csv-confirm]').check();
  const applyRequest=page.waitForRequest(request=>request.url().endsWith('/api/host-csv/apply'));
  await page.locator('[data-csv-action="apply"]').click();await applyRequest;
  await page.locator('nav [data-view="hosts"]').click();await page.locator('.host-card').first().waitFor();
  releaseApply();
  await page.locator('#notice .notice.error').filter({hasText:'Planungsdaten wurden seit der Vorschau geändert. CSV erneut prüfen.'}).waitFor();
  assert.equal(await page.locator('.host-card').count(),fixture.hosts.length);
  assert.equal(status.mutations,0);assert.equal(status.hostRequests,1);assert.deepEqual(errors,[]);
});
