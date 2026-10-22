export const severityLabels = {error: 'Fehler', warning: 'Warnung', info: 'Hinweis'};
export const categoryLabels = {addresses: 'Adressen', structure: 'Netzstruktur', gateways: 'Gateways', pools: 'Pools', capacity: 'Kapazität', routes: 'Routen'};
const rank = {error: 0, warning: 1, info: 2};
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));

export function filterFindings(report, filters = {}) {
  return (report?.findings || []).filter(finding =>
    (!filters.severity || finding.severity === filters.severity) &&
    (!filters.category || finding.category === filters.category) &&
    (!filters.site || finding.objects.some(object => String(object.site_id) === String(filters.site))) &&
    (!filters.space || finding.objects.some(object => String(object.address_space_id) === String(filters.space))) &&
    (!filters.entity || finding.objects.some(object => String(object.entity_id) === String(filters.entity))) &&
    (!filters.q || [finding.message, finding.code, finding.action, ...finding.objects.map(o => `${o.name} ${o.site_name}`)].join(' ').toLowerCase().includes(filters.q.toLowerCase()))
  );
}

export function objectHref(object) {
  const query = new URLSearchParams();
  let view;
  switch (object.kind) {
    case 'network':
      view = 'ipam'; query.set('network', object.id);
      if (object.site_id) query.set('site', object.site_id);
      break;
    case 'reservation':
      view = 'ipam'; query.set('network', object.network_id);
      if (object.site_id) query.set('site', object.site_id);
      break;
    case 'route': case 'vpn_interface':
      view = 'routers'; query.set('router', object.host_id); break;
    case 'interface': case 'legacy_interface':
      view = 'hosts'; query.set('focus', object.host_id || object.entity_id); break;
    case 'host': view = 'hosts'; query.set('focus', object.id); break;
    case 'site': view = 'sites'; query.set('focus', object.id); break;
    case 'address_space': view = 'ipam'; query.set('space', object.id); break;
    case 'virtual_network': view = 'ipam'; query.set('focus', object.id); break;
    default: return '#network-check';
  }
  return `#${view}?${query}`;
}

/** Cache is invalidated by domain mutations, never by camera/position changes. */
export function createNetworkCheck(fetchReport) {
  const model = {report: null, status: 'idle', stale: true, error: ''};
  let generation = 0, pending = null;
  return {
    model,
    invalidate() { generation++; model.stale = true; model.error = ''; if (!pending) model.status = 'idle'; },
    async load(force = false) {
      if (pending) { await pending; if (model.stale && !model.error) return this.load(); return model; }
      if (!force && model.status === 'error') return model;
      if (!force && model.report && !model.stale) return model;
      const started = generation;
      model.status = 'loading'; model.error = '';
      pending = (async () => {
        try {
          const report = await fetchReport('network-check');
          if (!report || !Array.isArray(report.findings) || !report.summary || !report.checked_at) throw new Error('Ungültige Prüfergebnisse.');
          model.report = report; model.stale = generation !== started; model.status = 'ready';
        } catch (error) {
          model.error = error.message || 'Netzcheck fehlgeschlagen.'; model.stale = true; model.status = 'error';
        }
      })();
      await pending; pending = null;
      return model;
    },
  };
}

export function renderCheckSummary(model) {
  const report = model.report;
  const counts = report ? Object.entries(severityLabels).map(([key,label]) => `<span class="check-count check-${key}">${report.summary[key]} ${label}${key === 'warning' && report.summary[key] !== 1 ? 'en' : key === 'info' && report.summary[key] !== 1 ? 'e' : ''}</span>`).join('') : '';
  const time = report ? new Date(report.checked_at).toLocaleString('de-AT') : '';
  let message = model.status === 'loading' ? 'Prüfung läuft …' : !report ? 'Noch kein Prüfergebnis.' : 'Stand: ' + time;
  if (model.stale && report) message = 'Ergebnis veraltet · letzter Stand: ' + time;
  if (model.error) message = 'Prüfung fehlgeschlagen: ' + model.error + (report ? ' · letzter Stand: ' + time : '');
  return `<section class="panel check-summary" aria-label="Netzcheck-Zusammenfassung"><div><h3>Netzcheck</h3><p role="status" class="${model.stale || model.error ? 'check-stale' : 'muted'}">${escapeHtml(message)}</p><div class="check-counts">${counts}</div></div><div class="actions"><a class="button secondary" href="#network-check">Prüfliste öffnen</a><button class="button secondary" data-action="network-check-refresh" ${model.status === 'loading' ? 'disabled' : ''}>Erneut prüfen</button></div></section>`;
}

export function renderCheckPage(model, filters = {}) {
  const report = model.report;
  const findings = filterFindings(report, filters);
  const select = (key,label,items) => `<label>${label}<select data-check-filter="${key}"><option value="">Alle</option>${items.map(([value,text]) => `<option value="${escapeHtml(value)}" ${String(filters[key] || '') === String(value) ? 'selected' : ''}>${escapeHtml(text)}</option>`).join('')}</select></label>`;
  return `<div class="page-head"><div><h2>Zentraler Netzcheck</h2><p>Prüft gespeicherte Planungsdaten. Hinweise belegen keine Erreichbarkeit im realen Netz.</p></div></div>${renderCheckSummary(model)}
    <div class="check-filters">
      ${select('site','Site',(report?.sites || []).map(o=>[o.id,o.name]))}
      ${select('space','Adressraum',(report?.address_spaces || []).map(o=>[o.id,o.name]))}
      ${select('severity','Schweregrad',Object.entries(severityLabels))}
      ${select('category','Prüfart',Object.entries(categoryLabels))}
      <a class="button secondary" href="#network-check">Filter zurücksetzen</a>
    </div>
    ${filters.entity ? `<p class="muted">Gefiltert auf Topologieobjekt #${escapeHtml(filters.entity)}.</p>` : ''}
    ${report?.coverage?.complete === false ? '<p class="check-stale">Altbestand vorhanden: Die Prüfung deckt noch nicht alle älteren Netzobjekte vollständig ab.</p>' : ''}
    <p class="muted">${findings.length} von ${report?.findings.length || 0} Befunden</p>
    <div class="check-results">${findings.map(finding=>`<article class="panel check-finding check-${finding.severity}" data-check-id="${escapeHtml(finding.id)}">
      <header><span class="check-level">${severityLabels[finding.severity]}</span><span>${categoryLabels[finding.category] || escapeHtml(finding.category)}</span></header>
      <h3>${escapeHtml(finding.message)}</h3><p>${escapeHtml(finding.action)}</p>
      <div class="check-object-links">${finding.objects.map(object=>`<a class="button small secondary" href="${escapeHtml(objectHref(object))}">${escapeHtml([object.site_name,object.name].filter(Boolean).join(' · '))} ↗</a>`).join('')}</div>
    </article>`).join('') || `<div class="panel empty">${model.error ? 'Kein aktuelles Ergebnis verfügbar.' : !report ? 'Prüfung noch nicht abgeschlossen.' : model.stale ? 'Ergebnis veraltet – bitte erneut prüfen.' : 'Keine Befunde für diese Auswahl.'}</div>`}</div>`;
}

/** Only references are inspected. Nodes and their bounds are never changed. */
export function markerModels(topology, report) {
  const byEntity = new Map();
  for (const finding of report?.findings || []) {
    for (const id of new Set(finding.objects.map(o=>o.entity_id).filter(Boolean).map(Number))) {
      const current = byEntity.get(id) || {severity: finding.severity, count: 0};
      if (rank[finding.severity] < rank[current.severity]) current.severity = finding.severity;
      current.count++; byEntity.set(id,current);
    }
  }
  return topology.nodes.filter(node=>byEntity.has(Number(node.id))).map(node=>{
    const halfWidth = node.host_table_source || node.host_table_id ? 125 : node.kind === 'host' ? 78 : 62;
    const halfHeight = node.host_table_id ? 12 : node.kind === 'host' ? 16 : 25;
    return {id:node.id, name:node.name, ...byEntity.get(Number(node.id)),
      x:node.region ? node.region.x+node.region.width-15 : node.x+halfWidth+10,
      y:node.region ? node.region.y+15 : node.host_table_id ? node.y : node.y-halfHeight+8};
  });
}

export function renderCheckMarkers(topology, model) {
  return markerModels(topology,model.report).map(marker=>{
    const label = `${marker.name}: ${marker.count} Netzcheck-Befunde (${severityLabels[marker.severity]})${model.stale ? ' · veraltet' : ''}`;
    return `<g class="check-marker check-${marker.severity} ${model.stale ? 'check-marker-stale' : ''}" data-check-entity="${marker.id}" transform="translate(${marker.x},${marker.y})" role="link" tabindex="0" aria-label="${escapeHtml(label)}"><title>${escapeHtml(label)}</title><circle r="9"/><text text-anchor="middle" y="3">${marker.severity === 'info' ? 'i' : '!'}</text></g>`;
  }).join('');
}
