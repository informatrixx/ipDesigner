import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {changes,renderCsvPreview} from '../public/assets/host-csv.js';

const fixture=JSON.parse(execFileSync('php',['tests/fixtures/browser-csv.php'],{encoding:'utf8'}));

test('CSV-Vorschau zeigt tatsächliche Änderungen einschließlich automatischer IPs',()=>{
  const html=renderCsvPreview(fixture.preview);
  assert.match(html,/10\.55\.0\.3/);
  assert.match(html,/Import-Host/);
  assert.match(html,/noch nicht gespeichert/);
  assert.match(html,/data-csv-action="apply" disabled/);
  assert.deepEqual(changes({name:'A',ips:['1']},{name:'B',ips:['1']}),[{field:'Name',before:'A',after:'B'}]);
});

test('CSV-Vorschau escaped Dateiinhalte und sperrt die Übernahme bei Fehlern',()=>{
  const preview=structuredClone(fixture.preview);
  preview.can_apply=false;preview.rows[0].name='<img src=x onerror=alert(1)>';
  preview.errors=[{record:3,message:'<script>bad()</script>'}];
  preview.network_check.findings=[{severity:'error',message:'<b>bad</b>',action:'<svg onload=x>'}];
  preview.network_check.summary.error=1;
  const html=renderCsvPreview(preview);
  assert.doesNotMatch(html,/<img|<script|<svg/);
  assert.match(html,/&lt;img/);assert.match(html,/Datensatz 3/);
  assert.doesNotMatch(html,/data-csv-action="apply"/);
});

test('CSV-Vorschau identifiziert das Interface auch bei unverändertem Namen',()=>{
  const preview=structuredClone(fixture.preview);
  const row=preview.rows[0];
  row.before.interface=structuredClone(row.after.interface);
  row.before.interface.ips=['10.55.0.4'];
  row.interface_action='updated';
  const html=renderCsvPreview(preview);
  assert.match(html,/<div class="csv-interface-context"><strong>eth0<\/strong> · lan<\/div>/);
  assert.doesNotMatch(html,/<b>Name:<\/b> <del>eth0<\/del>/);
  row.after.interface.name='<img src=x>';
  assert.match(renderCsvPreview(preview),/<strong>&lt;img src=x&gt;<\/strong>/);
});
