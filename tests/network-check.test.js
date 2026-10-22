import test from 'node:test';
import assert from 'node:assert/strict';
import {filterFindings, markerModels, createNetworkCheck, renderCheckPage, objectHref} from '../public/assets/network-check.js';

const object = {kind:'network',id:7,entity_id:7,name:'LAN',site_id:2,site_name:'Home',address_space_id:1};
const report = {checked_at:'2026-10-22T10:00:00Z',summary:{error:1,warning:0,info:0},findings:[{id:'a',code:'address.duplicate',category:'addresses',severity:'error',message:'Doppelte IP',action:'Prüfen',objects:[object,object]}]};

test('Befunde filtern und Links zeigen auf das richtige Netz', () => {
  assert.equal(filterFindings(report,{site:2,entity:7,severity:'error'}).length,1);
  assert.equal(filterFindings(report,{site:3}).length,0);
  assert.equal(filterFindings(report,{q:'home'}).length,1);
  assert.equal(objectHref(object),'#ipam?network=7&site=2');
});

test('Marker deduplizieren Objektverweise und verändern keine Geometrie', () => {
  const topology = {nodes:[{id:7,name:'LAN',x:120,y:100,host_table_source:true}]};
  const before = structuredClone(topology);
  assert.deepEqual(markerModels(topology,report),[{id:7,name:'LAN',severity:'error',count:1,x:255,y:83}]);
  assert.deepEqual(topology,before);
  topology.nodes[0].x += 20;
  assert.equal(markerModels(topology,report)[0].x,275);
});

test('Prüfungen werden zwischengespeichert und bei Änderung erneut geladen', async () => {
  let calls = 0;
  const check = createNetworkCheck(async () => {calls++;return report;});
  await check.load(); await check.load(); assert.equal(calls,1);
  check.invalidate(); await check.load(); assert.equal(calls,2);
  await check.load(true); assert.equal(calls,3);
});

test('Fehlschlag erhält alte Daten mit sichtbarer Warnung, niemals ein grünes Ergebnis', async () => {
  let fail = false;
  const check = createNetworkCheck(async () => {if(fail)throw Error('Server nicht erreichbar');return report;});
  await check.load(); fail = true; await check.load(true);
  assert.equal(check.model.stale,true); assert.equal(check.model.report,report);
  assert.match(renderCheckPage(check.model),/Prüfung fehlgeschlagen/);
  assert.match(renderCheckPage(check.model),/Server nicht erreichbar/);
});

test('Eine Datenänderung während der Prüfung macht deren Antwort veraltet', async () => {
  let finish;
  const check = createNetworkCheck(()=>new Promise(resolve=>{finish=resolve;}));
  const pending = check.load(); check.invalidate(); finish(report); await pending;
  assert.equal(check.model.stale,true);
});

test('Befundtexte und Objektbezeichnungen werden escaped', () => {
  const unsafe = structuredClone(report); unsafe.findings[0].message='<img src=x onerror=alert(1)>';
  const html = renderCheckPage({report:unsafe,status:'ready',stale:false,error:''});
  assert.doesNotMatch(html,/<img/); assert.match(html,/&lt;img/);
});
