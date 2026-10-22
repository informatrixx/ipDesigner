const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const actions = {created:'Neu',updated:'Ändern',unchanged:'Unverändert',none:'–'};
const fields = {site_id:'Site-ID',name:'Name',hostname:'Hostname',type:'Typ',status:'Status',description:'Beschreibung',is_router:'Router',interface_type:'Interface-Typ',network_id:'Netz-ID',pool_id:'Pool-ID',ips:'IPs',mac_address:'MAC',dns_name:'DNS',is_gateway:'Gateway'};

export function changes(before, after) {
  if (!after) return [];
  return Object.entries(after).filter(([key,value]) => JSON.stringify(before?.[key] ?? null) !== JSON.stringify(value))
    .map(([key,value]) => ({field:fields[key] || key,before:before?.[key] ?? null,after:value}));
}

export function renderCsvPreview(preview) {
  if (!preview) return '';
  const value = item => esc(Array.isArray(item) ? item.join(', ') : item ?? '–');
  const delta = (before,after) => changes(before,after).map(change=>`<div><b>${esc(change.field)}:</b> <del>${value(change.before)}</del> → <ins>${value(change.after)}</ins></div>`).join('') || '<span class="muted">Unverändert</span>';
  const summary = preview.summary;
  const check = preview.network_check;
  return `<section class="panel csv-preview" aria-label="CSV-Vorschau"><h3>Vorschau – noch nicht gespeichert</h3>
    <p>${summary.hosts_created} neue / ${summary.hosts_updated} geänderte Hosts · ${summary.interfaces_created} neue / ${summary.interfaces_updated} geänderte Interfaces</p>
    ${preview.errors.length ? `<div class="csv-error" role="alert"><strong>CSV-Fehler: nichts kann übernommen werden.</strong><ul>${preview.errors.map(error=>`<li>Datensatz ${error.record}: ${esc(error.message)}</li>`).join('')}</ul></div>` : ''}
    <p class="${check.summary.error ? 'csv-error' : 'muted'}">Netzcheck nach dem geplanten Import: ${check.summary.error} Fehler · ${check.summary.warning} Warnungen · ${check.summary.info} Hinweise</p>
    ${check.findings.length ? `<details class="csv-findings" ${check.summary.error ? 'open' : ''}><summary>Netzcheck-Befunde ansehen</summary><ul>${check.findings.map(f=>`<li><strong>${esc({error:'Fehler',warning:'Warnung',info:'Hinweis'}[f.severity])}:</strong> ${esc(f.message)} ${esc(f.action)}</li>`).join('')}</ul></details>` : ''}
    <div class="table-wrap"><table><thead><tr><th>Datensatz</th><th>Site / Host</th><th>Hoständerungen</th><th>Interfaceänderungen</th></tr></thead><tbody>${preview.rows.map(row=>`<tr><td>${row.record}</td><td><strong>${esc(row.name)}</strong><br>${esc(row.site)}</td><td><b>${actions[row.host_action]}</b>${delta(row.before.host,row.after.host)}</td><td>${row.after.interface ? `<div class="csv-interface-context"><strong>${esc(row.after.interface.name)}</strong> · ${esc(row.after.interface.interface_type)}</div>` : ''}<b>${actions[row.interface_action]}</b>${row.after.interface ? delta(row.before.interface,row.after.interface) : ''}</td></tr>`).join('')}</tbody></table></div>
    <p class="muted">Die Vorschau basiert auf einer isolierten Kopie. Bei zwischenzeitlichen Änderungen wird der Import abgelehnt und muss erneut geprüft werden. Netzcheck-Fehler blockieren die gesamte Datei; Warnungen und Hinweise bitte vor dem Bestätigen prüfen.</p>
    ${preview.can_apply ? '<label class="csv-confirm"><input type="checkbox" data-csv-confirm> Vorschau und Hinweise geprüft – alle angezeigten Änderungen gemeinsam übernehmen.</label><button class="button primary" data-csv-action="apply" disabled>Änderungen übernehmen</button>' : '<p class="csv-error">Bitte zuerst die CSV- bzw. Netzcheck-Fehler korrigieren.</p>'}
  </section>`;
}

export function downloadCsv(result) {
  const blob = new Blob([result.csv], {type:'text/csv;charset=utf-8'});
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a'); link.href = url; link.download = result.filename;
  document.body.append(link); link.click(); link.remove();
  setTimeout(()=>URL.revokeObjectURL(url), 1000);
}

export function mountHostCsv(root, {api,onApplied,onNotice = () => {},networks = [],virtualNetworks = []}) {
  const panel = document.createElement('section'); panel.className = 'csv-transfer'; root.replaceChildren(panel);
  const model = {csv:'',filename:'',updates:false,preview:null,busy:false,message:'',error:false,generation:0};
  const references = [...networks.filter(n=>n.node_type === 'leaf' || Number(n.l3_enabled)), ...virtualNetworks.filter(n=>n.network_type === 'vpn')];
  const render = () => {
    panel.innerHTML = `<div class="page-head"><div><h2>CSV-Import / Export</h2><p>Hosts und LAN-/VPN-Interfaces in Excel bearbeiten und kontrolliert übernehmen.</p></div><a class="button secondary" href="#hosts">Zur Hostliste</a></div>
      <section class="panel csv-editor"><div class="actions"><button class="button secondary" data-csv-action="export">Alle Hosts exportieren</button><button class="button secondary" data-csv-action="template">Leere Vorlage herunterladen</button></div>
      <p>Export: UTF-8, Semikolon, eine Zeile pro Interface. Import: auch Komma oder Tabulator; maximal 500 Datensätze / 512 KiB. Für einen gefilterten Export die <a href="#hosts">Hostliste</a> verwenden.</p>
      <label>CSV-Datei <input type="file" data-csv-file accept=".csv,.tsv,text/csv,text/tab-separated-values"></label>
      ${model.filename ? `<p class="muted">Geladen: ${esc(model.filename)}</p>` : ''}
      <label>CSV-Inhalt <textarea data-csv-text rows="9" spellcheck="false" placeholder="site;name;hostname;interface_name;network;ips">${esc(model.csv)}</textarea></label>
      <label class="csv-confirm"><input type="checkbox" data-csv-updates ${model.updates ? 'checked' : ''}> Bestehende IDs aktualisieren (bewusst aktivieren für bearbeitete Exporte)</label>
      <p class="muted">Leere IDs legen neue Objekte an. Mehrere Zeilen desselben neuen Hosts müssen identische Hostfelder enthalten. Fehlende Zeilen löschen nichts. Leere LAN-IP: nächste freie Adresse, bei Routern das Gateway. VPN-IPs bitte ausdrücklich angeben.</p>
      <button class="button primary" data-csv-action="preview">Vorschau und Netzcheck erstellen</button>
      <p role="status" class="csv-status ${model.error ? 'csv-error' : ''}">${esc(model.message)}</p></section>
      <details class="panel csv-help"><summary>Spalten und vorhandene Zielnetze</summary>
      <p><code>site;name;hostname</code> sind Pflichtspalten. Für ein Interface zusätzlich <code>interface_name</code> und <code>network</code> oder <code>network_id</code>. Für neue Hosts/Interfaces IDs leer lassen; bestehende nur über ihre exportierten IDs ändern. Mehrere IPs mit <code>|</code> trennen. Absolute FQDNs enden mit einem Punkt.</p>
      <p><code>interface_type</code>: lan oder vpn. Optional: <code>type</code>, <code>status</code>, <code>description</code>, <code>is_router</code>, <code>pool_id</code>/<code>pool</code>, <code>mac_address</code>, <code>dns_name</code>, <code>is_gateway</code>, <code>confirm_dhcp_overlap</code>. Ja/Nein: 1/0. IDs und mitgelieferte Namen müssen zusammenpassen. Die Vorlage enthält alle Spalten.</p>
      <p>Sites, Netze und Pools müssen bereits vorhanden sein. Keine Löschungen, Site-Wechsel bestehender Hosts oder Änderungen ihrer Routerrolle. Aliasse, Notizen, Dienste, Routen und Docker-Netze werden nicht exportiert oder ersetzt. Ein CSV-Export ist kein vollständiges Backup. In Formeln deutbare Textwerte sind durch ein führendes Apostroph geschützt.</p>
      <div class="table-wrap"><table><thead><tr><th>Site</th><th>Name</th><th>ID</th><th>Typ</th><th>CIDR</th></tr></thead><tbody>${references.map(n=>`<tr><td>${esc(n.site_name || 'Global')}</td><td>${esc(n.name)}</td><td>${n.id}</td><td>${esc(n.network_type || n.allocation_type || 'subnet')}</td><td>${esc(n.cidr)}</td></tr>`).join('')}</tbody></table></div></details>
      <div data-csv-result>${renderCsvPreview(model.preview)}</div>`;
    if (model.busy) panel.querySelectorAll('input,textarea,button').forEach(input=>input.disabled=true);
  };
  const invalidate = () => {
    model.generation++; model.preview=null; model.message='CSV geändert – vor dem Übernehmen erneut prüfen.'; model.error=false;
    panel.querySelector('[data-csv-result]').innerHTML='';
    const status=panel.querySelector('.csv-status');status.textContent=model.message;status.classList.remove('csv-error');
  };
  panel.addEventListener('input', event=>{
    if (event.target.matches('[data-csv-text]')) {model.csv=event.target.value;invalidate();}
  });
  panel.addEventListener('change', async event=>{
    if (event.target.matches('[data-csv-updates]')) {model.updates=event.target.checked;invalidate();}
    if (event.target.matches('[data-csv-confirm]')) panel.querySelector('[data-csv-action="apply"]').disabled=!event.target.checked || model.busy;
    if (!event.target.matches('[data-csv-file]')) return;
    const file=event.target.files[0];if(!file)return;
    invalidate();const generation=model.generation;model.csv='';model.filename='';
    try {
      if (file.size>524288) throw new Error('Datei ist zu groß (maximal 512 KiB).');
      const csv=new TextDecoder('utf-8',{fatal:true}).decode(await file.arrayBuffer());
      if (model.generation!==generation) return;
      model.csv=csv;model.filename=file.name;model.message='Datei geladen – Vorschau erstellen.';
    } catch(error) {if(model.generation!==generation)return;model.message=error.message;model.error=true;}
    render();
  });
  panel.addEventListener('click', async event=>{
    const button=event.target.closest('[data-csv-action]');if(!button||model.busy)return;
    const action=button.dataset.csvAction;
    if(action==='apply'&&(!model.preview?.can_apply||!panel.querySelector('[data-csv-confirm]')?.checked))return;
    const generation=model.generation;
    model.busy=true;model.message=action==='preview'?'CSV und Netzplan werden geprüft …':action==='apply'?'Änderungen werden gemeinsam gespeichert …':'CSV wird erstellt …';model.error=false;render();
    try {
      if(action==='export'||action==='template') {downloadCsv(await api(`host-csv/${action}`));model.message='CSV heruntergeladen.';}
      if(action==='preview') {
        const result=await api('host-csv/preview',{method:'POST',body:JSON.stringify({csv:model.csv,allow_updates:model.updates})});
        if(generation===model.generation){model.preview=result;model.message=result.can_apply?'Vorschau bereit. Bitte Änderungen und Hinweise prüfen.':'Vorschau enthält Fehler; nichts wurde gespeichert.';}
      }
      if(action==='apply') {
        await api('host-csv/apply',{method:'POST',body:JSON.stringify({csv:model.csv,allow_updates:model.updates,revision:model.preview.revision,plan_hash:model.preview.plan_hash})});
        model.preview=null;model.csv='';model.filename='';model.generation++;
        model.message='CSV-Import vollständig gespeichert.';
        try {await onApplied();} catch {model.message+=' Die Ansicht konnte nicht aktualisiert werden; bitte Seite neu laden.';model.error=true;}
      }
    } catch(error) {model.preview=null;model.message=error.message;model.error=true;}
    finally {
      model.busy=false;
      if(panel.isConnected) render();
      else if(action==='apply') onNotice(model.message,model.error);
    }
  });
  render();
}
