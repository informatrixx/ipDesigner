const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const restoreNoticeKey = 'ipdesigner.snapshot-restored';
const severityLabels = {error:'Fehler',warning:'Warnung',info:'Hinweis'};

export function snapshotRestoreMessage(result) {
  const rescue = result.rescue_snapshot;
  return `Planung wiederhergestellt. Rettungssnapshot: ${rescue.name} (${rescue.id}).`;
}

export function consumeSnapshotRestoreNotice() {
  try {
    const notice = sessionStorage.getItem(restoreNoticeKey);
    sessionStorage.removeItem(restoreNoticeKey);
    return notice;
  } catch { return null; }
}

function reloadAfterRestore(result) {
  try { sessionStorage.setItem(restoreNoticeKey, snapshotRestoreMessage(result)); } catch { /* Reload still discards stale planning state. */ }
  window.location.reload();
}

export function renderSnapshotPreview(preview) {
  if (!preview) return '';
  const counts = preview.summary;
  const check = preview.network_check;
  const rowValue = row => row === null ? '<span class="muted">Nicht vorhanden</span>' : `<pre>${esc(JSON.stringify(row, null, 2))}</pre>`;
  return `<section class="panel snapshot-preview" aria-label="Wiederherstellungsvorschau"><h3>Wiederherstellung prüfen: ${esc(preview.snapshot.name)}</h3>
    <p class="snapshot-warning"><strong>Der gesamte aktuelle Plan wird ersetzt.</strong> Änderungen seit diesem Snapshot gehen aus dem aktiven Plan verloren. Vor der Übernahme wird automatisch ein Rettungssnapshot des aktuellen Plans angelegt. Noch wurde nichts wiederhergestellt.</p>
    <p>${counts.added} Datensätze hinzufügen · ${counts.changed} ändern · ${counts.removed} entfernen</p>
    <div class="table-wrap"><table><thead><tr><th>Bereich</th><th>Aktuell</th><th>Nach Wiederherstellung</th><th>Neu</th><th>Geändert</th><th>Entfernt</th></tr></thead><tbody>${preview.tables.map(table=>`<tr><td>${esc(table.label)}</td><td>${table.before_count}</td><td>${table.after_count}</td><td>${table.added}</td><td>${table.changed}</td><td>${table.removed}</td></tr>`).join('')}</tbody></table></div>
    <details class="snapshot-changes"><summary>Änderungsdetails (${preview.changes.length}${preview.changes_truncated ? ', gekürzt' : ''})</summary>
      ${preview.changes_truncated ? '<p class="snapshot-warning">Die Detailansicht ist gekürzt. Die Tabellenzahlen oben beziehen sich auf sämtliche Änderungen.</p>' : ''}
      ${preview.changes.map(change=>`<details><summary>${esc(change.label)} · ${esc(change.table)} · ${esc(change.key)}</summary><div class="snapshot-row-diff"><div><h4>Aktuell</h4>${rowValue(change.before)}</div><div><h4>Nach Wiederherstellung</h4>${rowValue(change.after)}</div></div></details>`).join('') || '<p class="muted">Keine Datenänderungen.</p>'}
    </details>
    <p class="${check.summary.error ? 'snapshot-error' : 'muted'}">Netzcheck des wiederhergestellten Plans: ${check.summary.error} Fehler · ${check.summary.warning} Warnungen · ${check.summary.info} Hinweise</p>
    ${check.findings.length ? `<details class="snapshot-findings" ${check.summary.error ? 'open' : ''}><summary>Netzcheck-Befunde ansehen</summary><ul>${check.findings.map(finding=>`<li><strong>${esc(severityLabels[finding.severity])}:</strong> ${esc(finding.message)} ${esc(finding.action)}</li>`).join('')}</ul></details>` : ''}
    <p class="muted">Ändert sich der Plan zwischen Vorschau und Übernahme, ist eine neue Vorschau erforderlich. Die gesamte Anwendung wird nach erfolgreicher Wiederherstellung neu geladen.</p>
    ${preview.can_restore ? `<label class="snapshot-confirm"><input type="checkbox" data-snapshot-confirm> Ich habe die Vorschau geprüft und möchte den gesamten aktuellen Plan durch diesen Snapshot ersetzen.</label>
      ${preview.requires_error_confirmation ? '<label class="snapshot-confirm snapshot-error"><input type="checkbox" data-snapshot-confirm-errors> Ich möchte den Snapshot trotz der angezeigten Netzcheck-Fehler wiederherstellen.</label>' : ''}
      <button type="button" class="button danger" data-snapshot-action="restore" disabled>Gesamten Plan wiederherstellen</button>` : '<p class="snapshot-error">Dieser Snapshot kann nicht wiederhergestellt werden.</p>'}
  </section>`;
}

export function mountPlanningSnapshots(root, {api,onNotice = () => {},onRestored = reloadAfterRestore}) {
  const panel = document.createElement('section'); panel.className = 'planning-snapshots'; root.replaceChildren(panel);
  const model = {snapshots:[],preview:null,busy:false,ready:false,error:false,message:'',name:'',description:''};
  const render = () => {
    panel.innerHTML = `<div class="page-head"><div><h2>Planungssnapshots</h2><p>Den gesamten Planungsstand sichern, vergleichen und kontrolliert wiederherstellen.</p></div></div>
      <p class="snapshot-warning">Snapshots enthalten die vollständigen Planungsdaten einschließlich Beziehungen und Topologiepositionen. Sie liegen in derselben SQLite-Datenbank und ersetzen kein externes Backup.</p>
      <section class="panel snapshot-create"><h3>Aktuellen Plan sichern</h3><form data-snapshot-create>
        <label>Name <input name="name" data-snapshot-name value="${esc(model.name)}" maxlength="120" required placeholder="Zum Beispiel: Vor dem Netzumbau"></label>
        <label>Beschreibung <textarea name="description" data-snapshot-description rows="2" maxlength="2000" placeholder="Was zeichnet diesen Stand aus?">${esc(model.description)}</textarea></label>
        <button class="button primary" type="submit" ${model.ready ? '' : 'disabled'}>Snapshot erstellen</button>
      </form></section>
      <p class="snapshot-status ${model.error ? 'snapshot-error' : ''}" role="status">${esc(model.message)}</p>
      <section class="panel snapshot-list"><div class="panel-head"><h3>Gesicherte Planungsstände</h3><button type="button" class="button secondary" data-snapshot-action="reload">Liste aktualisieren</button></div>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Erstellt</th><th>Umfang</th><th></th></tr></thead><tbody>${model.snapshots.map(snapshot=>`<tr><td><strong>${esc(snapshot.name)}</strong><span class="snapshot-kind">${snapshot.kind === 'pre_restore' ? 'Rettungssnapshot' : 'Manuell'}</span><small>${esc(snapshot.description)}</small><small class="muted">ID: ${esc(snapshot.id)}</small></td><td>${esc(snapshot.created_at)}</td><td>${snapshot.summary?.sites ?? 0} Sites · ${snapshot.summary?.networks ?? 0} Netze · ${snapshot.summary?.hosts ?? 0} Hosts</td><td><button type="button" class="button secondary" data-snapshot-action="preview" data-snapshot-id="${esc(snapshot.id)}">Wiederherstellung prüfen</button></td></tr>`).join('') || `<tr><td colspan="4">${model.ready ? 'Noch keine Snapshots angelegt.' : 'Snapshotliste noch nicht geladen.'}</td></tr>`}</tbody></table></div>
      </section>${renderSnapshotPreview(model.preview)}`;
    if(model.busy) panel.querySelectorAll('input,textarea,button').forEach(input=>input.disabled=true);
  };
  const perform = async (action, operation) => {
    if(model.busy)return;
    model.busy=true;model.error=false;model.message={load:'Snapshots werden geladen …',create:'Planungsstand wird gesichert …',preview:'Wiederherstellung und Netzcheck werden geprüft …',restore:'Plan wird wiederhergestellt …'}[action];render();
    try { await operation(); }
    catch(error) {model.preview=null;model.message=error.message;model.error=true;}
    finally {
      model.busy=false;
      if(panel.isConnected)render();
      else if(action==='create'||action==='restore'||model.error)onNotice(model.message,model.error);
    }
  };
  const loadList = () => perform('load', async()=>{
    model.preview=null;model.snapshots=await api('snapshots');model.ready=true;model.message='';
  });
  panel.addEventListener('input',event=>{
    if(event.target.matches('[data-snapshot-name]'))model.name=event.target.value;
    if(event.target.matches('[data-snapshot-description]'))model.description=event.target.value;
  });
  panel.addEventListener('change',event=>{
    if(!event.target.matches('[data-snapshot-confirm],[data-snapshot-confirm-errors]'))return;
    const confirmed=panel.querySelector('[data-snapshot-confirm]')?.checked;
    const errorsConfirmed=!model.preview?.requires_error_confirmation||panel.querySelector('[data-snapshot-confirm-errors]')?.checked;
    panel.querySelector('[data-snapshot-action="restore"]').disabled=model.busy||!confirmed||!errorsConfirmed;
  });
  panel.addEventListener('submit',event=>{
    if(!event.target.matches('[data-snapshot-create]'))return;
    event.preventDefault();if(model.busy||!model.ready)return;
    perform('create',async()=>{
      model.preview=null;
      const snapshot=await api('snapshots',{method:'POST',body:JSON.stringify({name:model.name,description:model.description})});
      model.snapshots=[snapshot,...model.snapshots.filter(item=>item.id!==snapshot.id)];model.name='';model.description='';model.message=`Snapshot „${snapshot.name}“ erstellt.`;
    });
  });
  panel.addEventListener('click',event=>{
    const button=event.target.closest('[data-snapshot-action]');if(!button||model.busy)return;
    const action=button.dataset.snapshotAction;
    if(action==='reload'){loadList();return;}
    if(action==='preview'){
      perform('preview',async()=>{
        model.preview=null;
        model.preview=await api(`snapshots/${encodeURIComponent(button.dataset.snapshotId)}/preview`,{method:'POST',body:'{}'});
        model.message='Vorschau bereit. Noch wurde nichts wiederhergestellt.';
      });return;
    }
    if(action!=='restore'||!model.preview?.can_restore||!panel.querySelector('[data-snapshot-confirm]')?.checked)return;
    const confirmErrors=Boolean(panel.querySelector('[data-snapshot-confirm-errors]')?.checked);
    if(model.preview.requires_error_confirmation&&!confirmErrors)return;
    const preview=model.preview;
    perform('restore',async()=>{
      const result=await api(`snapshots/${encodeURIComponent(preview.snapshot.id)}/restore`,{method:'POST',body:JSON.stringify({revision:preview.revision,plan_hash:preview.plan_hash,confirm_restore:true,confirm_errors:confirmErrors})});
      model.preview=null;model.message=snapshotRestoreMessage(result);onRestored(result);
    });
  });
  render();loadList();
}
