import test from 'node:test';
import assert from 'node:assert/strict';
import {renderSnapshotPreview,snapshotRestoreMessage,consumeSnapshotRestoreNotice} from '../public/assets/planning-snapshots.js';

function preview() {
  return {snapshot:{id:1,name:'Vor dem Umbau'},summary:{added:0,changed:1,removed:1},
    tables:[{table:'hosts',label:'Hosts',before_count:2,after_count:1,added:0,changed:1,removed:1}],
    changes:[{table:'hosts',key:3,label:'Server',before:{id:3,name:'Server'},after:{id:3,name:'Alter Server'}}],
    changes_truncated:false,network_check:{summary:{error:0,warning:0,info:1},findings:[{severity:'info',message:'Gateway fehlt.',action:'Router planen.'}]},
    requires_error_confirmation:false,can_restore:true};
}

test('Snapshotvorschau zeigt vollständigen Ersatz, Tabellenzahlen und Änderungsvorher/nachher',()=>{
  const html=renderSnapshotPreview(preview());
  assert.match(html,/Der gesamte aktuelle Plan wird ersetzt/);
  assert.match(html,/Rettungssnapshot/);
  assert.match(html,/0 Datensätze hinzufügen · 1 ändern · 1 entfernen/);
  assert.match(html,/Alter Server/);
  assert.match(html,/data-snapshot-action="restore" disabled/);
  assert.doesNotMatch(html,/data-snapshot-confirm-errors/);
});

test('Netzcheckfehler verlangen eine separate Bestätigung; nicht wiederherstellbare Vorschau bleibt gesperrt',()=>{
  const data=preview();data.requires_error_confirmation=true;data.network_check.summary.error=1;
  assert.match(renderSnapshotPreview(data),/data-snapshot-confirm-errors/);
  data.can_restore=false;
  assert.doesNotMatch(renderSnapshotPreview(data),/data-snapshot-action="restore"/);
});

test('Snapshotdetails escapen Metadaten und Dateninhalte und kennzeichnen gekürzte Änderungen',()=>{
  const data=preview();data.snapshot.name='<img src=x>';data.changes[0].label='<script>bad</script>';
  data.changes[0].before={name:'<svg onload=x>'};data.changes[0].after=null;data.changes_truncated=true;
  data.network_check.findings[0].message='<b>Problem</b>';
  const html=renderSnapshotPreview(data);
  assert.doesNotMatch(html,/<img|<script|<svg/);assert.match(html,/&lt;img/);assert.match(html,/&lt;svg/);
  assert.match(html,/Detailansicht ist gekürzt/);assert.match(html,/Nicht vorhanden/);
});

test('Restoremeldung benennt den Rettungssnapshot; fehlender Browserspeicher bricht nichts ab',()=>{
  assert.equal(snapshotRestoreMessage({rescue_snapshot:{id:7,name:'Vor Wiederherstellung'}}),'Planung wiederhergestellt. Rettungssnapshot: Vor Wiederherstellung (7).');
  assert.equal(consumeSnapshotRestoreNotice(),null);
});
