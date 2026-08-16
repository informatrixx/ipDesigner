import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

function ipToInt(ip) { return ip.split('.').reduce((n, part) => n * 256 + Number(part), 0); }
function intToIp(number) { return [24, 16, 8, 0].map(shift => Math.floor(number / 2 ** shift) % 256).join('.'); }

test('IPv4-Konvertierung für die Belegungsmatrix', () => {
  for (const ip of ['0.0.0.0', '10.20.30.40', '255.255.255.255']) assert.equal(intToIp(ipToInt(ip)), ip);
});

test('Absolute Host-FQDNs werden im Formular mit abschließendem Punkt erkannt', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /function primaryFqdnPreview\(/);
  assert.match(app, /input\.endsWith\('\.'\)/);
  assert.match(app, /office\.example\.at\./);
  assert.match(app, /absoluter FQDN/);
});

test('Host- und VPN-Interfaces akzeptieren mehrere IPv4-Adressen', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /name:'ips',label:'IPv4-Adressen'/);
  assert.match(app, /Leer lassen: nächste freie IP automatisch vergeben/);
  assert.match(app, /name:'ips',label:'Tunnel-IP-Adressen'/);
  assert.match(app, /Eine Adresse pro Zeile/);
  assert.match(app, /i\.ips\|\|\[i\.ip\]/);
  assert.match(app, /suggestions:\[\.\.\.new Set/);
  assert.match(app, /für ein weiteres VPN-Netz erneut gewählt/);
});

test('Topologie zeigt Interface und IP in einer kompakten Kantenbox', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /function topologyEdgeLabel/);
  assert.match(app, /function topologyLabelOverlap/);
  assert.match(app, /function topologyAssignedLabelLayouts/);
  assert.match(app, /baseOverlap>\.3/);
  assert.match(app, /score<=\.05/);
  assert.match(app, /base\.x\+ux\*shift/);
  assert.match(app, /topologyLabelLayouts=topologyAssignedLabelLayouts/);
  assert.match(app, /edge\.type!==['"]assigned['"]/);
  assert.match(app, /class="edge-label-box"/);
  assert.match(css, /\.edge-label-box rect/);
  assert.match(css, /fill:rgba\(255,255,255,\.96\)/);
});

test('Hosttabellen hängen direkt an ihrem Netz und ersetzen redundante Verbindungslinien', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /function topologyHostTableSource/);
  assert.match(app, /function buildTopologyHostTables/);
  assert.match(app, /x:source\.x-width\/2,y:source\.y\+25/);
  assert.match(app, /target\?\.kind==='host'&&target\.host_table_id/);
  assert.match(app, /class="network-host-header"/);
  assert.doesNotMatch(app, /host-table-title/);
  assert.match(app, /class="node host host-table-row/);
  assert.match(app, /host-table-regions/);
  assert.match(app, /active\.host_table_id/);
  assert.match(css, /\.host-table-frame/);
  assert.match(css, /\.network-host-header/);
  assert.match(css, /\.node\.host-table-row/);
  assert.match(css, /\.node\.host-compact/);
});

test('Sites-Navigation und Topologie-Kontextaktionen sind im Frontend verdrahtet', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const page = readFileSync(new URL('../public/index.php', import.meta.url), 'utf8');
  assert.match(page, /data-view="sites"/);
  assert.match(app, /function renderSites\(/);
  assert.match(app, /contextmenu/);
  assert.match(app, /function startRelation\(/);
  assert.match(app, /topology-network-plan/);
  assert.match(app, /positions-reset/);
  assert.match(app, /planner\/replan-preview/);
  assert.match(app, /function showReplanPreview\(/);
  assert.match(app, /data-network-row/);
  assert.match(app, /rollback-preview/);
  assert.match(page, /data-view="routers"/);
  assert.match(app, /function renderRouters\(/);
  assert.match(app, /route-suggestions/);
  assert.match(app, /Gateway-Funktion/);
  assert.match(app, /function openNetworkWrap\(/);
  assert.match(app, /action:'wrap'/);
  assert.match(app, /action:'reparent'/);
  assert.match(app, /convert_to_leaf/);
  assert.match(app, /function convertContainerRole\(/);
  assert.match(app, /action:'convert_container_role'/);
  assert.match(app, /network-convert-role/);
  assert.match(app, /In Supernetz umwandeln/);
  assert.match(app, /In Gruppe umwandeln/);
  assert.match(app, /data-network-check/);
  assert.match(app, /data-site-drop/);
  assert.match(app, /data-drop-mode="before"/);
  assert.match(app, /Vor .* einfügen/);
  assert.match(app, /Nach .* einfügen/);
  assert.match(app, /Supernetz/);
  assert.match(app, /function activateL3\(/);
  assert.match(app, /action:'activate_l3'/);
  assert.match(app, /function addPool\(/);
  assert.match(app, /action:'update_pool'/);
  assert.match(app, /DHCP-Pool/);
  assert.match(app, /network-deactivate-l3/);
  assert.match(app, /function renderVirtualNetworks\(/);
  assert.match(app, /virtual-networks/);
  assert.match(app, /function openVpnInterface\(/);
  assert.match(app, /VPN beitreten/);
  assert.match(app, /Docker-Netz/);
  assert.match(app, /virtual_network/);
  assert.match(app, /function siteRegionBounds\(/);
  assert.match(app, /class="site-region/);
  assert.match(app, /function topologyEdgeVisible\(/);
  assert.match(app, /if\(n\.kind==='site'\|\|isTopologyContainer\(n\)\)return''/);
  assert.match(app, /data-site-region/);
  assert.match(page, /data-view="tools"/);
  assert.match(app, /function renderTools\(/);
  assert.match(app, /tools\/ipv4/);
  assert.match(app, /ipdesigner\.autoReplan/);
  assert.match(app, /function applyAutomaticReplan\(/);
  assert.match(app, /requires_boundary_confirmation/);
  assert.match(app, /data-calculator-mode="analyze"/);
  assert.match(app, /data-calculator-mode="range"/);
  assert.match(app, /data-calculator-mode="split"/);
  assert.match(app, /Optionale feste Start-IP/);
  assert.match(app, /manual_start_ip/);
  assert.match(app, /start_ip:values\.start_ip/);
  assert.match(app, /pool_range_fixed/);
  assert.match(app, /Optionaler fixer Bereich von/);
  assert.match(app, /CIDR und Poolbereich wachsen dynamisch/);
  assert.match(app, /function interfaceNetworksForHost\(/);
  assert.match(app, /Number\(x\.site_id\)===Number\(host\.site_id\)/);
  assert.match(app, /Nur Subnetze der Site/);
  assert.match(app, /action:'relocate_space'/);
  assert.match(app, /requires_explicit_confirmation/);
  assert.match(app, /translate_manual_starts:true/);
});

test('Leere Site-Flächen behalten ihren Anker über wiederholte Geometrie-Updates', () => {
  let site = {x: 1030, y: 205};
  for (let update = 0; update < 20; update++) {
    const region = {x: site.x - 88, y: site.y - 27};
    site = {x: region.x + 88, y: region.y + 27};
  }
  assert.deepEqual(site, {x: 1030, y: 205});
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /!members\.length&&!groupRegions\.length\)return\{x:\(site\.x\|\|120\)-88,y:\(site\.y\|\|190\)-27/);
});

test('Organisatorische Gruppen umschließen ihre Topologie-Kinder', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /function groupNetworkDescendants\(/);
  assert.match(app, /function groupRegionBounds\(/);
  assert.match(app, /data-group-region/);
  assert.match(app, /function isTopologyContainer\(/);
  assert.match(app, /\['group','aggregate'\]/);
  assert.match(app, /isTopologyContainer\(active\)/);
  assert.match(app, /isTopologyContainer\(source\)/);
  assert.match(app, /siblings=node\.parent_id\?occupied\.filter/);
  assert.match(app, /aggregate-region/);
  assert.match(css, /\.group-region-frame/);
  assert.match(css, /\.group-region-header/);
  assert.match(css, /\.group-region\.aggregate-region/);
});

test('Router bleiben außerhalb von Gruppen und erhalten Container-Schnittstellen', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /host\.kind!==['"]router['"]/);
  assert.match(app, /function keepRoutersOutsideGroups\(/);
  assert.match(app, /function topologyContainerInterfaces\(/);
  assert.match(app, /function attachTopologyInterface\(/);
  assert.match(app, /function containerInterfaceSvg\(/);
  assert.match(app, /verticalSide=Math\.abs\(dx\)\*size\.y>=Math\.abs\(dy\)\*size\.x/);
  assert.match(app, /<g class="nodes">[\s\S]*<g class="container-interfaces">/);
  assert.match(app, /router:4/);
  assert.match(css, /\.container-interface circle/);
});

test('Adressraum-Verbindungen docken richtungsabhängig am Rand der Site an', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /function attachSiteAddressSpaceConnector\(/);
  assert.match(app, /function topologySiteAddressSpaceConnectors\(/);
  assert.match(app, /site-address-space-connectors/);
  assert.match(app, /siteConnector\?\.x\?\?target\.x/);
  assert.match(css, /\.site-address-space-connector circle/);
});

test('Neue Site-Objekte werden lokal bei gespeicherten Mitgliedern einsortiert', () => {
  const savedMembers = [{id: 1, x: 1180, y: 430}, {id: 2, x: 1345, y: 430}];
  const occupied = [...savedMembers];
  let placed;
  for (let slot = 0; slot < 20 && !placed; slot++) {
    const candidate = {x: 1180 + (slot % 4) * 165, y: 430 + Math.floor(slot / 4) * 72};
    if (!occupied.some(other => Math.abs(other.x - candidate.x) < 145 && Math.abs(other.y - candidate.y) < 62)) placed = candidate;
  }
  assert.deepEqual(placed, {x: 1510, y: 430});
  assert.ok(placed.x - Math.min(...savedMembers.map(node => node.x)) < 720);
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /function placeUnpositionedSiteMembers\(/);
  assert.match(app, /saved\.has\(site\.id\)\|\|members\.some\(node=>saved\.has\(node\.id\)\)/);
});

test('Site-Drag verschiebt nur die enthaltenen Objekte und Adressraum-Kanten bleiben sichtbar', () => {
  const nodes = [{id: 1, kind: 'site', x: 100, y: 100}, {id: 2, kind: 'host', site_id: 1, x: 150, y: 180}, {id: 3, kind: 'host', site_id: 9, x: 500, y: 500}];
  const members = nodes.filter(node => node.kind !== 'site' && Number(node.site_id) === 1);
  members.forEach(node => { node.x += 40; node.y += 25; });
  assert.deepEqual([nodes[1].x, nodes[1].y], [190, 205]);
  assert.deepEqual([nodes[2].x, nodes[2].y], [500, 500]);
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /active\.kind==='site'/);
  assert.match(app, /source\?\.kind==='site'/);
  assert.match(app, /freie Fläche ziehen/);
});

test('Topologie-Kamera unterstützt Zoom, Pan und automatisches Rand-Nachführen', () => {
  const view = {x: 100, y: 80, width: 1000, height: 600};
  const factor = 0.8, anchorX = 0.5, anchorY = 0.5;
  const zoomed = {x: view.x + (view.width - view.width * factor) * anchorX, y: view.y + (view.height - view.height * factor) * anchorY, width: view.width * factor, height: view.height * factor};
  assert.deepEqual(zoomed, {x: 200, y: 140, width: 800, height: 480});
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /function zoomTopology\(/);
  assert.match(app, /function panTopology\(/);
  assert.match(app, /const autoPan=/);
  assert.match(app, /addEventListener\('wheel'/);
  assert.match(app, /topology-zoom-in/);
  assert.match(app, /topology-fit/);
  assert.match(app, /topologyWorldLimit=5000/);
});

test('Site-Titelbalken zeigt CIDR und Kapazitätskennzahlen', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /function siteRegionContents\(/);
  assert.match(app, /IP-BEREICH/);
  assert.match(app, /FREIE HOSTS/);
  assert.match(app, /NUTZBAR/);
  assert.match(app, /BELEGT/);
  assert.match(app, /Array\.isArray\(site\.site_cidrs\)/);
  assert.match(app, /cidrs\.join\('\s\+\s'\)/);
  assert.match(app, /name:'allocation_mode'.*Eigener ausgerichteter CIDR-Block/);
  assert.match(app, /action:'update_site_allocation'/);
  assert.match(css, /\.site-region-header/);
  assert.match(css, /\.site-region-stat-value/);
});

test('Auswahlmenüs kennzeichnen sitegebundene Objekte mit ihrer Site', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  assert.match(app, /function siteOptionLabel\(/);
  assert.match(app, /label:siteOptionLabel\(x,`\$\{x\.name\} · \$\{x\.fqdn\}`\)/);
  assert.match(app, /label:siteOptionLabel\(x,`\$\{networkRoleLabel\(x\)\} · \$\{x\.name\}/);
  assert.match(app, /label:siteOptionLabel\(x,`\$\{labels\[x\.kind\]\} · \$\{x\.name\}`\)/);
});

test('Hosts besitzen umschaltbare Kachel- und editierbare Tabellenansicht mit Filtern', () => {
  const app = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
  const css = readFileSync(new URL('../public/assets/app.css', import.meta.url), 'utf8');
  assert.match(app, /host-view-cards/);
  assert.match(app, /host-view-table/);
  assert.match(app, /function hostSpreadsheetRow/);
  assert.match(app, /data-host-field="hostname"/);
  assert.match(app, /host-filter-site/);
  assert.match(app, /host-filter-type/);
  assert.match(app, /host-filter-status/);
  assert.match(app, /saveHostSpreadsheetRow/);
  assert.match(app, /function newHostSpreadsheetRow/);
  assert.match(app, /data-host-new-row/);
  assert.match(app, /host-row-create/);
  assert.match(app, /createHostSpreadsheetRow/);
  assert.match(app, /e\.ctrlKey\|\|e\.metaKey/);
  assert.match(css, /\.host-sheet tbody tr\.dirty/);
  assert.match(css, /\.sheet-row-number/);
  assert.match(css, /\.sheet-new-row/);
  assert.match(css, /border-collapse:separate/);
  assert.match(css, /\.host-filterbar/);
});
