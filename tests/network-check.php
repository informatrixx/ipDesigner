<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/network-check.php';

function checkCodes(array $fixture): array
{
    return array_column((new IpDesigner\NetworkCheck($fixture['db']))->run()['findings'], 'code');
}

test('Netzcheck bleibt lesend und liefert stabile Befunde für einen sauberen Plan', function (): void {
    $f = networkCheckFixture(); $db = $f['db'];
    $before = $db->query('SELECT total_changes()')->fetchColumn();
    $db->exec('PRAGMA query_only=ON');
    $checker = new IpDesigner\NetworkCheck($db);
    $result = $checker->run();
    equal(['error'=>0,'warning'=>0,'info'=>0],$result['summary']);
    equal($result['revision'],$checker->run()['revision']);
    equal($before,$db->query('SELECT total_changes()')->fetchColumn());
    equal(false,$db->inTransaction());
});

test('Gateway wird in Dashboard, Site und Netz nur einmal abgezogen', function (): void {
    $f = networkCheckFixture();
    $f['db']->prepare('DELETE FROM entities WHERE id=?')->execute([$f['host']['id']]);
    equal(5,$f['repo']->dashboard()['free']);
    equal(5,$f['repo']->sites()[0]['free_addresses']);
    equal(5,$f['planner']->network($f['network'])['free_count']);
    equal(5,(new IpDesigner\NetworkCheck($f['db']))->run()['capacity'][$f['network']]['free_count']);
});

test('Netzcheck erkennt tabellenübergreifende Dubletten, Poolfehler und falsche Adressen', function (): void {
    $f = networkCheckFixture(); $db = $f['db'];
    $db->prepare('INSERT INTO network_reservations(network_id,ip_int,label) VALUES (?,?,?)')->execute([$f['network'],IpDesigner\IpMath::toInt('10.55.0.2'),'Doppelt']);
    $db->prepare('INSERT INTO network_interface_addresses(interface_id,ip_int) VALUES (?,?)')->execute([$f['interface']['id'],IpDesigner\IpMath::toInt('10.55.1.20')]);
    $pool = fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Falscher Pool','10.55.2.0/26',['allocation_type'=>'static_pool','gateway_int'=>null]);
    $db->prepare('UPDATE network_interfaces SET pool_id=? WHERE id=?')->execute([$pool,$f['interface']['id']]);
    $codes = checkCodes($f);
    foreach (['address.duplicate','address.outside','address.pool_assignment','address.pool_bounds','pool.bounds'] as $code) equal(true,in_array($code,$codes,true),$code);
    $result = (new IpDesigner\NetworkCheck($db))->run();
    equal(4,$result['capacity'][$f['network']]['free_count']); // Duplicate occupancy is counted once.
});

test('Netzcheck erkennt Zyklus, CIDR-Widerspruch und unerlaubte Überlappung', function (): void {
    $f = networkCheckFixture(); $db = $f['db'];
    $parent = fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Gruppe','10.55.0.0/24',['node_type'=>'container','gateway_int'=>null,'entity_kind'=>'block']);
    $db->prepare('UPDATE network_nodes SET parent_id=? WHERE id=?')->execute([$parent,$parent]);
    fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Kollision','10.55.0.0/28');
    $db->prepare('UPDATE network_nodes SET broadcast_int=broadcast_int-1 WHERE id=?')->execute([$f['network']]);
    foreach (['network.cycle','network.cidr_mismatch','network.overlap'] as $code) equal(true,in_array($code,checkCodes($f),true),$code);
});

test('L3-Pools nutzen ihre Randadressen und werden nicht zusätzlich zur Site addiert', function (): void {
    $f = networkCheckFixture(); $db = $f['db']; $space = (int)$f['space']['id']; $site = (int)$f['site']['id'];
    $group = fixtureNetwork($db,$space,$site,'Gruppe','10.55.1.0/24',['node_type'=>'container','gateway_int'=>null,'entity_kind'=>'block']);
    $l3 = fixtureNetwork($db,$space,$site,'Gemeinsam','10.55.1.0/25',['node_type'=>'container','l3_enabled'=>1,'parent_id'=>$group]);
    $static = fixtureNetwork($db,$space,$site,'Statisch','10.55.1.0/26',['parent_id'=>$l3,'allocation_type'=>'static_pool','gateway_int'=>null]);
    $dhcp = fixtureNetwork($db,$space,$site,'DHCP','10.55.1.64/26',['parent_id'=>$l3,'allocation_type'=>'dhcp_pool','gateway_int'=>null]);
    $f['repo']->saveInterface(['host_id'=>$f['host']['id'],'network_id'=>$l3,'pool_id'=>$static,'name'=>'eth1','ips'=>['10.55.1.62','10.55.1.63']]);
    $f['repo']->saveInterface(['host_id'=>$f['host']['id'],'network_id'=>$l3,'pool_id'=>$dhcp,'name'=>'eth2','ip'=>'10.55.1.64','confirm_dhcp_overlap'=>1]);
    $capacity = IpDesigner\NetworkCapacity::load($db);
    equal(62,$capacity[$static]['host_capacity']); equal(63,$capacity[$dhcp]['host_capacity']);
    equal(125,$capacity[$l3]['host_capacity']); equal(122,$capacity[$l3]['free_count']);
    equal(130,$f['repo']->dashboard()['usable']);
    equal(130,$f['repo']->sites()[0]['usable_addresses']);
    $report = (new IpDesigner\NetworkCheck($db))->run(); equal(0,$report['summary']['error']);
    equal(true,in_array('pool.static_in_dhcp',array_column($report['findings'],'code'),true));
});

test('Kapazitätsberechnung behandelt /31, /32 und /0 ohne Adressauflistung', function (): void {
    $db = IpDesigner\Database::connect(':memory:'); IpDesigner\Database::migrate($db);
    $nodes = [];
    foreach (['0.0.0.0/0','10.0.0.0/31','10.0.0.3/32'] as $index=>$cidr) {
        $net = IpDesigner\IpMath::parseCidr($cidr);
        $nodes[$index+1] = ['id'=>$index+1,'cidr'=>$cidr,'node_type'=>'leaf','gateway_int'=>null,'network_int'=>$net['network'],'broadcast_int'=>$net['broadcast']];
    }
    $capacity = IpDesigner\NetworkCapacity::calculate($nodes,[],[]);
    equal(4294967294,$capacity[1]['free_count']); equal(2,$capacity[2]['free_count']); equal(1,$capacity[3]['free_count']);
});

test('Globale VPN-Interfaces dürfen mehrere IPs und sitefremde Router verbinden', function (): void {
    $f = networkCheckFixture();
    $vpn = $f['repo']->saveVirtualNetwork(['network_type'=>'vpn','name'=>'Global','cidr'=>'10.56.0.0/24']);
    $router = $f['repo']->saveRouter(['site_id'=>$f['otherSite']['id'],'name'=>'Remote','hostname'=>'remote']);
    $f['repo']->saveVpnInterface(['virtual_network_id'=>$vpn['id'],'router_id'=>$router['id'],'name'=>'wg0','ips'=>['10.56.0.1','10.56.0.2']]);
    equal(0,(new IpDesigner\NetworkCheck($f['db']))->run()['summary']['error']);
});

test('Netzcheck erkennt veraltete Routenziele, eigene Next Hops und Gateway-Bindungen', function (): void {
    $f = networkCheckFixture(); $db = $f['db'];
    $route = $f['repo']->saveStaticRoute(['router_id'=>$f['router']['id'],'destination_network_id'=>$f['network'],'destination_cidr'=>'10.55.0.0/29','egress_interface_id'=>$f['gateway']['id'],'next_hop'=>'10.55.0.2']);
    $db->prepare('UPDATE static_routes SET destination_cidr=?,next_hop_int=? WHERE id=?')->execute(['10.77.0.0/24',IpDesigner\IpMath::toInt('10.55.0.1'),$route['id']]);
    $db->prepare('UPDATE gateway_bindings SET address_offset=2 WHERE network_id=?')->execute([$f['network']]);
    foreach (['route.target','route.next_hop','gateway.binding'] as $code) equal(true,in_array($code,checkCodes($f),true),$code);
});

test('Docker-CIDRs dürfen auf verschiedenen Geräten wiederverwendet werden', function (): void {
    $f = networkCheckFixture();
    foreach ([$f['host']['id'],$f['router']['id']] as $owner) $f['repo']->saveVirtualNetwork(['network_type'=>'docker_bridge','name'=>'Docker '.$owner,'owner_host_id'=>$owner,'cidr'=>'172.17.0.0/16']);
    equal(0,(new IpDesigner\NetworkCheck($f['db']))->run()['summary']['error']);
});

test('Beschädigte numerische IPs führen zu Befunden statt zum Abbruch', function (): void {
    $f = networkCheckFixture();
    $f['db']->prepare('UPDATE network_interfaces SET ip_int=-10 WHERE id=?')->execute([$f['interface']['id']]);
    equal(true,in_array('address.invalid',checkCodes($f),true));
});

test('Migration ist explizit und Schemafehler sind verständlich', function (): void {
    $db = IpDesigner\Database::connect(':memory:');
    equal(0,(int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
    throws(fn()=>IpDesigner\Database::assertReady($db),IpDesigner\SchemaNotReady::class);
    IpDesigner\Database::migrate($db); IpDesigner\Database::assertReady($db);
    $db->exec('PRAGMA query_only=ON'); IpDesigner\Database::assertReady($db);
});

test('Kapazitätswarnungen starten bei 80 Prozent und Befund-IDs bleiben stabil', function (): void {
    $f = networkCheckFixture();
    foreach (['10.55.0.3','10.55.0.4','10.55.0.5'] as $ip) $f['repo']->saveReservation(['network_id'=>$f['network'],'ip'=>$ip,'label'=>$ip]);
    $checker = new IpDesigner\NetworkCheck($f['db']); $a = $checker->run(); $b = $checker->run();
    equal(80.0,$a['capacity'][$f['network']]['utilization']);
    equal(array_column($a['findings'],'id'),array_column($b['findings'],'id'));
    equal(true,in_array('capacity.low',array_column($a['findings'],'code'),true));
});
