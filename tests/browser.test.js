import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Real PHP-generated page and data, intercepted HTTP. No live DB or PHP server.
const fixture = JSON.parse(execFileSync('php',['tests/fixtures/browser-data.php'],{encoding:'utf8'}));
const pageHtml = execFileSync('php',['public/index.php'],{encoding:'utf8',env:{...process.env,IPDESIGNER_BASE_PATH:'/codex/ipDesigner'}});
const origin = 'http://ipdesigner.test/codex/ipDesigner';

async function openFixture(t) {
  const browser = await chromium.launch({headless:true});
  t.after(()=>browser.close());
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  page.setDefaultTimeout(10000);
  const errors=[]; page.on('pageerror',error=>errors.push(error.message));
  const status = {checkRequests:0, fail:false, failNetworks:false, mutations:0};
  await page.route('**/*',async route=>{
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/codex/ipDesigner','');
    if(path.startsWith('/api/')) {
      const resource = path.slice(5);
      if(resource === 'network-check') status.checkRequests++;
      if ((resource === 'network-check' && status.fail) || (resource === 'networks' && status.failNetworks)) return route.fulfill({status:500,json:{error:{message:'Testfehler'}}});
      if (route.request().method() !== 'GET') {status.mutations++;return route.fulfill({json:{data:{saved:true}}});}
      return route.fulfill({json:{data:fixture[resource] ?? []}});
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
