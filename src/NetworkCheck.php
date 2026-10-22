<?php
declare(strict_types=1);

namespace IpDesigner;

use PDO;

/** Read-only inspection of a single database snapshot. No planners or network I/O. */
final class NetworkCheck
{
    private array $tables = [];
    private array $findings = [];
    private array $parsed = [];

    public function __construct(private readonly PDO $db) {}

    public function run(): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            foreach (['network_nodes','sites','address_spaces','hosts','network_interfaces',
                'network_interface_addresses','network_reservations','virtual_networks',
                'vpn_interfaces','vpn_interface_addresses','gateway_bindings','static_routes',
                'address_blocks','subnets','interfaces'] as $table) {
                $rows = $this->db->query('SELECT * FROM '.$table)->fetchAll();
                $this->tables[$table] = array_column($rows, null, $table === 'gateway_bindings' ? 'network_id' : 'id');
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->findings = $this->parsed = [];
        $this->checkNetworks();
        $this->checkAddresses();
        $this->checkGateways();
        $this->checkRoutes();
        $addresses = [];
        foreach ($this->tables['network_interfaces'] as $interface) {
            $addresses[] = $interface;
        }
        foreach ($this->tables['network_interface_addresses'] as $address) {
            $interface = $this->tables['network_interfaces'][$address['interface_id']] ?? null;
            if ($interface) $addresses[] = array_replace($interface, ['ip_int'=>$address['ip_int']]);
        }
        $capacity = NetworkCapacity::calculate($this->tables['network_nodes'], $addresses, $this->tables['network_reservations']);
        foreach ($capacity as $id => $usage) {
            if ($usage['utilization'] >= 80 || $usage['free_count'] === 0) {
                $this->add('capacity.low', 'warning', 'capacity',
                    $usage['free_count'].' freie Adressen · '.$usage['utilization'].' % belegt.',
                    'Bedarf und Wachstumsreserve prüfen; bei Bedarf in der Adressplanung erweitern.', [$this->ref('network', $id)]);
            }
        }
        $legacy = count($this->tables['address_blocks']) + count($this->tables['subnets']) + count($this->tables['interfaces']);
        if ($legacy) $this->add('coverage.legacy', 'info', 'structure',
            $legacy.' Datensätze verwenden das ältere Netzmodell. Ihre IP-Dubletten werden geprüft, Struktur und Kapazität noch nicht vollständig.',
            'Altbestand vor einer Überführung in die aktuelle Adressplanung separat prüfen.', []);
        $findings = array_values($this->findings);
        $rank = ['error'=>0, 'warning'=>1, 'info'=>2];
        usort($findings, static fn($a, $b) => [$rank[$a['severity']], $a['code'], $a['id']] <=> [$rank[$b['severity']], $b['code'], $b['id']]);
        $summary = ['error'=>0, 'warning'=>0, 'info'=>0];
        foreach ($findings as $finding) $summary[$finding['severity']]++;
        return ['checked_at'=>gmdate('c'), 'revision'=>hash('sha256', json_encode($this->tables, JSON_THROW_ON_ERROR)),
            'summary'=>$summary, 'coverage'=>['complete'=>$legacy === 0, 'legacy_records'=>$legacy],
            'findings'=>$findings, 'capacity'=>$capacity,
            'sites'=>array_values(array_map(static fn($s)=>['id'=>(int)$s['id'],'name'=>$s['name']], $this->tables['sites'])),
            'address_spaces'=>array_values(array_map(static fn($s)=>['id'=>(int)$s['id'],'name'=>$s['name']], $this->tables['address_spaces']))];
    }

    private function ref(string $kind, int $id): array
    {
        $table = match ($kind) {
            'network'=>'network_nodes', 'host'=>'hosts', 'interface'=>'network_interfaces',
            'vpn_interface'=>'vpn_interfaces', 'virtual_network'=>'virtual_networks',
            'reservation'=>'network_reservations', 'route'=>'static_routes', 'site'=>'sites',
            'address_space'=>'address_spaces', default=>null,
        };
        $row = $table ? ($this->tables[$table][$id] ?? []) : [];
        $hostId = (int)($row['host_id'] ?? $row['router_id'] ?? $row['owner_host_id'] ?? 0);
        $networkId = (int)($row['network_id'] ?? 0);
        $network = $this->tables['network_nodes'][$networkId] ?? [];
        $siteId = (int)($row['site_id'] ?? $this->tables['hosts'][$hostId]['site_id'] ?? $network['site_id'] ?? 0);
        if ($kind === 'site') $siteId = $id;
        $site = $this->tables['sites'][$siteId] ?? [];
        $spaceId = (int)($row['address_space_id'] ?? $network['address_space_id'] ?? $site['address_space_id'] ?? 0);
        if ($kind === 'address_space') $spaceId = $id;
        $entityId = match ($kind) {
            'interface','vpn_interface','route'=>$hostId,
            'reservation'=>(int)($row['pool_id'] ?? $networkId),
            'address_space'=>(int)($row['entity_id'] ?? 0),
            'network','site','host','virtual_network'=>$id,
            default=>0,
        };
        return ['kind'=>$kind, 'id'=>$id, 'entity_id'=>$entityId ?: null,
            'name'=>$row['name'] ?? $row['label'] ?? $row['destination_cidr'] ?? $kind.' #'.$id,
            'site_id'=>$siteId ?: null, 'site_name'=>$site['name'] ?? '',
            'address_space_id'=>$spaceId ?: null, 'address_space_name'=>$this->tables['address_spaces'][$spaceId]['name'] ?? '',
            'host_id'=>$hostId ?: null, 'network_id'=>$kind === 'network' ? $id : ($networkId ?: null)];
    }

    private function add(string $code, string $severity, string $category, string $message, string $action, array $objects, string $detail = ''): void
    {
        $keys = array_map(static fn($r) => $r['kind'].':'.$r['id'], $objects);
        sort($keys);
        $id = $code.':'.substr(hash('sha256', implode('|', $keys).'|'.$detail), 0, 16);
        $this->findings[$id] = compact('id','code','severity','category','message','action','objects');
    }

    private function ancestors(int $id): array
    {
        $seen = [];
        while (isset($this->tables['network_nodes'][$id])) {
            $id = (int)($this->tables['network_nodes'][$id]['parent_id'] ?? 0);
            if (!$id || isset($seen[$id])) break;
            $seen[$id] = true;
        }
        return $seen;
    }

    private function checkNetworks(): void
    {
        $nodes = $this->tables['network_nodes'];
        $ranges = [];
        foreach (['network'=>$nodes, 'virtual_network'=>$this->tables['virtual_networks']] as $kind=>$rows) {
            foreach ($rows as $id=>$node) {
                $ref = $this->ref($kind, (int)$id);
                try { $net = IpMath::parseCidr($node['cidr']); }
                catch (\InvalidArgumentException) {
                    $this->add('network.cidr_invalid','error','structure','Ungültiges oder nicht ausgerichtetes CIDR.', 'Netzadresse und Präfix prüfen.', [$ref]);
                    continue;
                }
                $this->parsed[$kind.':'.$id] = $net;
                if ((int)$node['network_int'] !== $net['network'] || (int)$node['broadcast_int'] !== $net['broadcast'] || (int)$node['prefix'] !== $net['prefix']) {
                    $this->add('network.cidr_mismatch','error','structure','CIDR und gespeicherte Bereichsgrenzen widersprechen sich.', 'Adressplan prüfen und kontrolliert neu berechnen.', [$ref]);
                }
                if ($kind === 'virtual_network' || NetworkCapacity::isL3($node)) {
                    $ranges[] = ['start'=>$net['network'],'end'=>$net['broadcast'],'ref'=>$ref];
                }
                if ($kind !== 'network') continue;
                $site = $this->tables['sites'][$node['site_id']] ?? null;
                $space = $this->tables['address_spaces'][$node['address_space_id']] ?? null;
                if (!$space || ($node['site_id'] !== null && (!$site || (int)$site['address_space_id'] !== (int)$node['address_space_id']))) {
                    $this->add('network.assignment','error','structure','Site und Adressraum sind nicht konsistent zugeordnet.', 'Site-Zuordnung in der Adressplanung prüfen.', [$ref]);
                } elseif ($net['network'] < (int)$space['start_int'] || ($space['max_int'] !== null && $net['broadcast'] > (int)$space['max_int'])) {
                    $this->add('network.space_bounds','error','structure','Netz liegt außerhalb der definierten Adressraumgrenzen.', 'Start-IP und Netzplatzierung prüfen.', [$ref]);
                }
                $ancestors = $this->ancestors((int)$id);
                if (isset($ancestors[$id])) {
                    $this->add('network.cycle','error','structure','Die Netzhierarchie enthält einen Zyklus.', 'Elternzuordnung vor weiteren Planänderungen korrigieren.', [$ref]);
                }
                $parent = $nodes[$node['parent_id']] ?? null;
                if ($node['parent_id'] !== null && (!$parent || $parent['node_type'] !== 'container' || (int)$parent['site_id'] !== (int)$node['site_id'] || (int)$parent['address_space_id'] !== (int)$node['address_space_id'] || $net['network'] < (int)$parent['network_int'] || $net['broadcast'] > (int)$parent['broadcast_int'])) {
                    $this->add('network.parent','error','structure','Elternnetz, Site oder Bereichsgrenzen passen nicht zum Kind.', 'Übergeordnetes Netz und Verschachtelung prüfen.', [$ref]);
                }
                if (NetworkCapacity::isPool($node)) {
                    $l3Id = NetworkCapacity::l3Id($node, $nodes);
                    [$start,$end] = NetworkCapacity::poolRange($node);
                    $l3 = $nodes[$l3Id] ?? null;
                    if (!$l3 || empty($l3['l3_enabled']) || $start > $end || $start < $net['network'] || $end > $net['broadcast'] || $start < (int)$l3['network_int'] || $end > (int)$l3['broadcast_int']) {
                        $this->add('pool.bounds','error','pools','Poolbereich oder Zuordnung zum gemeinsamen L3-Netz ist ungültig.', 'Poolgrenzen und L3-Zuordnung prüfen.', [$ref]);
                    } else {
                        $ranges[] = ['start'=>$start,'end'=>$end,'ref'=>$ref,'pool_l3'=>$l3Id];
                    }
                }
            }
        }
        usort($ranges, static fn($a,$b)=>$a['start'] <=> $b['start']);
        $active = [];
        foreach ($ranges as $range) {
            $active = array_filter($active, static fn($other)=>$other['end'] >= $range['start']);
            foreach ($active as $other) {
                $a = $range['ref']; $b = $other['ref'];
                if ($a['kind'] === 'virtual_network' && $b['kind'] === 'virtual_network') {
                    $va = $this->tables['virtual_networks'][$a['id']];
                    $vb = $this->tables['virtual_networks'][$b['id']];
                    if ($va['network_type'] === 'docker_bridge' && $vb['network_type'] === 'docker_bridge' && (int)$va['owner_host_id'] !== (int)$vb['owner_host_id']) continue;
                }
                // Pools deliberately overlap their shared L3 network, but not each other.
                if (($range['pool_l3'] ?? null) === ($b['kind']==='network' ? $b['id'] : null) && isset($range['pool_l3'])) continue;
                if (($other['pool_l3'] ?? null) === ($a['kind']==='network' ? $a['id'] : null) && isset($other['pool_l3'])) continue;
                $this->add('network.overlap','error','structure','Adressbereiche überlappen: '.$a['name'].' und '.$b['name'].'.', 'Platzierung oder Poolgrenzen in der Adressplanung prüfen.', [$a,$b]);
            }
            $active[] = $range;
        }
    }

    private function checkAddresses(): void
    {
        $used = [];
        $secondaryByInterface = $dhcpByNetwork = [];
        foreach (['network_interface_addresses','vpn_interface_addresses'] as $table) {
            foreach ($this->tables[$table] as $address) $secondaryByInterface[$table][$address['interface_id']][] = (int)$address['ip_int'];
        }
        foreach ($this->tables['network_nodes'] as $candidate) {
            if (($candidate['allocation_type'] ?? '') === 'dhcp_pool') {
                $l3 = NetworkCapacity::l3Id($candidate,$this->tables['network_nodes']);
                if ($l3 !== null) $dhcpByNetwork[$l3][] = $candidate;
            }
        }
        foreach (['interface'=>'network_interfaces','vpn_interface'=>'vpn_interfaces','reservation'=>'network_reservations'] as $kind=>$table) {
            foreach ($this->tables[$table] as $id=>$row) {
                $addresses = [(int)$row['ip_int']];
                $secondary = $kind === 'interface' ? 'network_interface_addresses' : ($kind === 'vpn_interface' ? 'vpn_interface_addresses' : null);
                if ($secondary) $addresses = [...$addresses, ...($secondaryByInterface[$secondary][$id] ?? [])];
                $ref = $this->ref($kind, (int)$id);
                $networkKind = $kind === 'vpn_interface' ? 'virtual_network' : 'network';
                $networkId = (int)($row['virtual_network_id'] ?? $row['network_id']);
                $networkRef = $this->ref($networkKind, $networkId);
                $net = $this->parsed[$networkKind.':'.$networkId] ?? null;
                $network = $this->tables[$networkKind === 'network' ? 'network_nodes' : 'virtual_networks'][$networkId] ?? null;
                $hostId = (int)($row['host_id'] ?? $row['router_id'] ?? 0);
                if (!$net || ($kind !== 'reservation' && !isset($this->tables['hosts'][$hostId])) || ($networkKind==='network' && $network && !NetworkCapacity::isL3($network))) {
                    $this->add('address.assignment','error','addresses','Host oder verwendbares L3-Netz der Adresse fehlt.', 'Interface- bzw. Reservierungszuordnung prüfen.', [$ref,$networkRef]);
                }
                $poolId = (int)($row['pool_id'] ?? 0);
                $pool = $this->tables['network_nodes'][$poolId] ?? null;
                if ($poolId && (!$pool || !NetworkCapacity::isPool($pool) || NetworkCapacity::l3Id($pool,$this->tables['network_nodes']) !== $networkId)) {
                    $this->add('address.pool_assignment','error','pools','Der ausgewählte Pool gehört nicht zum verwendeten L3-Netz.', 'Passenden Pool auswählen.', [$ref,$networkRef]);
                }
                foreach ($addresses as $index=>$ip) {
                    $used[$ip][] = $ref;
                    if ($ip < 0 || $ip > 4294967295) {
                        $this->add('address.invalid','error','addresses','Ungültiger IPv4-Zahlenwert: '.$ip.'.', 'Gespeicherte Adresse prüfen.', [$ref], (string)$ip);
                        continue;
                    }
                    if ($net && !IpMath::isUsable($ip,$net)) $this->add('address.outside','error','addresses',IpMath::toIp($ip).' ist im zugehörigen Netz nicht nutzbar.', 'IP-Adresse oder Netzzuordnung korrigieren.', [$ref,$networkRef], (string)$ip);
                    if ($pool && NetworkCapacity::isPool($pool)) {
                        [$start,$end] = NetworkCapacity::poolRange($pool);
                        if ($ip < $start || $ip > $end) $this->add('address.pool_bounds','error','pools',IpMath::toIp($ip).' liegt außerhalb des ausgewählten Pools.', 'Adresse oder Poolauswahl prüfen.', [$ref,$this->ref('network',$poolId)], (string)$ip);
                    }
                    if ($kind === 'interface' && $network && $network['gateway_int'] !== null && $ip === (int)$network['gateway_int']) {
                        $binding = $this->tables['gateway_bindings'][$networkId] ?? null;
                        if (!$binding || (int)$binding['interface_id'] !== (int)$id || $index !== 0) $this->add('gateway.address_conflict','error','gateways','Gateway-Adresse wird ohne passende Gateway-Bindung verwendet.', 'Router-Interface und Gateway-Funktion prüfen.', [$ref,$networkRef]);
                    }
                    if ($kind === 'reservation' && $network && $network['gateway_int'] !== null && $ip === (int)$network['gateway_int']) $this->add('gateway.reserved','error','gateways','Gateway-Adresse ist zusätzlich als Hostadresse reserviert.', 'Reservierung prüfen.', [$ref,$networkRef]);
                    if ($kind === 'interface') foreach ($dhcpByNetwork[$networkId] ?? [] as $candidate) {
                        [$start,$end] = NetworkCapacity::poolRange($candidate);
                        if ($ip >= $start && $ip <= $end && ($network['gateway_int'] === null || $ip !== (int)$network['gateway_int'])) {
                            $this->add('pool.static_in_dhcp','info','pools','Statische Interface-Adresse im DHCP-Pool: '.IpMath::toIp($ip).'.', 'Bewusste Vergabe und Ausschluss in der tatsächlichen DHCP-Konfiguration prüfen.', [$ref,$this->ref('network',(int)$candidate['id'])], (string)$ip);
                        }
                    }
                }
            }
        }
        foreach (['network_interface_addresses'=>'network_interfaces','vpn_interface_addresses'=>'vpn_interfaces'] as $table=>$parent) foreach ($this->tables[$table] as $address) {
            if (!isset($this->tables[$parent][$address['interface_id']])) $this->add('address.orphan','error','addresses','Zusatzadresse ohne zugehöriges Interface.', 'Verwaiste Zusatzadresse kontrolliert bereinigen.', [], $table.':'.$address['id']);
        }
        foreach ($this->tables['interfaces'] as $row) $used[(int)$row['ip_int']][] = ['kind'=>'legacy_interface','id'=>(int)$row['id'],'entity_id'=>(int)$row['host_id'],'name'=>$row['name'],'site_id'=>null,'site_name'=>'','address_space_id'=>null,'address_space_name'=>''];
        foreach ($used as $ip=>$refs) if (count($refs) > 1) $this->add('address.duplicate','error','addresses','IP-Adresse mehrfach vergeben: '.($ip >= 0 && $ip <= 4294967295 ? IpMath::toIp((int)$ip) : (string)$ip).'.', 'Betroffene Interfaces und Reservierungen vergleichen; eine freie Adresse zuweisen.', $refs, (string)$ip);
    }

    private function checkGateways(): void
    {
        foreach ($this->tables['network_nodes'] as $id=>$node) {
            if (!NetworkCapacity::isL3($node)) continue;
            $net = $this->parsed['network:'.$id] ?? null;
            $ref = $this->ref('network',(int)$id);
            $gateway = $node['gateway_int'];
            if ($gateway !== null && $net && !IpMath::isUsable((int)$gateway,$net)) $this->add('gateway.outside','error','gateways','Gateway liegt außerhalb der nutzbaren Netzadressen.', 'Gateway-Adresse korrigieren.', [$ref]);
            $binding = $this->tables['gateway_bindings'][$id] ?? null;
            if (!$binding && $gateway !== null) {
                $active = false;
                foreach ($this->tables['network_interfaces'] as $interface) if ((int)$interface['network_id'] === (int)$id && ($this->tables['hosts'][$interface['host_id']]['status'] ?? '') === 'active') $active = true;
                $this->add('gateway.unbound',$active ? 'warning' : 'info','gateways','Geplantes Gateway hat noch kein Router-Interface.', 'Gateway bei Bedarf einem Router-Interface zuweisen.', [$ref]);
            }
        }
        foreach ($this->tables['gateway_bindings'] as $id=>$binding) {
            $node = $this->tables['network_nodes'][$id] ?? null;
            $interface = $this->tables['network_interfaces'][$binding['interface_id']] ?? null;
            $host = $this->tables['hosts'][$interface['host_id'] ?? 0] ?? null;
            if (!$node || !NetworkCapacity::isL3($node) || !$interface || !$host || !$host['is_router'] || (int)$interface['network_id'] !== (int)$id || $node['gateway_int'] === null || (int)$interface['ip_int'] !== (int)$node['gateway_int'] || (int)$binding['address_offset'] !== (int)$node['gateway_int'] - (int)$node['network_int']) {
                $this->add('gateway.binding','error','gateways','Gateway-Bindung passt nicht zu Netz, Router oder Interface-Adresse.', 'Gateway-Funktion am Router-Interface prüfen.', [$this->ref('network',(int)$id),$this->ref('interface',(int)$binding['interface_id'])]);
            }
        }
    }

    private function checkRoutes(): void
    {
        foreach ($this->tables['static_routes'] as $id=>$route) {
            $ref = $this->ref('route',(int)$id);
            $interface = $this->tables['network_interfaces'][$route['egress_interface_id']] ?? null;
            $host = $this->tables['hosts'][$route['router_id']] ?? null;
            if (!$interface || !$host || !$host['is_router'] || (int)$interface['host_id'] !== (int)$route['router_id']) {
                $this->add('route.interface','error','routes','Ausgangsinterface gehört nicht zum angegebenen Router.', 'Router und Ausgangsinterface der Route prüfen.', [$ref]);
                continue;
            }
            $net = $this->parsed['network:'.$interface['network_id']] ?? null;
            $ownIps = [(int)$interface['ip_int']];
            foreach ($this->tables['network_interface_addresses'] as $address) if ((int)$address['interface_id'] === (int)$interface['id']) $ownIps[] = (int)$address['ip_int'];
            if (!$net || !IpMath::isUsable((int)$route['next_hop_int'],$net) || in_array((int)$route['next_hop_int'],$ownIps,true)) $this->add('route.next_hop','error','routes','Next Hop ist über das Ausgangsinterface nicht sinnvoll adressierbar.', 'Next Hop und Ausgangsnetz prüfen.', [$ref,$this->ref('interface',(int)$interface['id'])]);
            try { IpMath::parseCidr($route['destination_cidr']); }
            catch (\InvalidArgumentException) { $this->add('route.cidr','error','routes','Ungültiges Ziel-CIDR.', 'Zieladresse und Präfix korrigieren.', [$ref]); }
            if ($route['destination_network_id'] !== null) {
                $target = $this->tables['network_nodes'][$route['destination_network_id']] ?? null;
                if (!$target || !NetworkCapacity::isL3($target) || $target['cidr'] !== $route['destination_cidr']) $this->add('route.target','error','routes','Gespeichertes Ziel-CIDR stimmt nicht mit dem referenzierten Zielnetz überein.', 'Route an das aktuelle Zielnetz anpassen.', [$ref,$this->ref('network',(int)$route['destination_network_id'])]);
            }
        }
    }
}
