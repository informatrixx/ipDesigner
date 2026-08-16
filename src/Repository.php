<?php
declare(strict_types=1);

namespace IpDesigner;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

final class Repository
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function entity(string $kind): int
    {
        $stmt = $this->db->prepare('INSERT INTO entities(kind) VALUES (?)');
        $stmt->execute([$kind]);
        return (int) $this->db->lastInsertId();
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $result = $callback();
            if ($ownsTransaction) $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private static function text(array $data, string $key, string $default = ''): string
    {
        return trim((string) ($data[$key] ?? $default));
    }

    private static function required(array $data, string $key, string $label): string
    {
        $value = self::text($data, $key);
        if ($value === '') throw new InvalidArgumentException("$label ist erforderlich.");
        return $value;
    }

    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        return $value === '' || $value === null ? null : (int) $value;
    }

    private function l3NetworkId(array $node): ?int
    {
        if (!empty($node['l3_enabled'])) return (int)$node['id'];
        for ($parent=$node['parent_id']??null; $parent!==null;) {
            $candidate=$this->one('SELECT id,parent_id,l3_enabled FROM network_nodes WHERE id=?',[(int)$parent]);
            if (!$candidate) return null;
            if (!empty($candidate['l3_enabled'])) return (int)$candidate['id'];
            $parent=$candidate['parent_id'];
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function sites(): array
    {
        $sites = $this->all("SELECT s.*, a.name address_space_name, a.routing_domain, a.start_ip,
            (SELECT COUNT(*) FROM network_nodes n WHERE n.site_id=s.id AND n.node_type='container' AND n.l3_enabled=0) area_count,
            (SELECT COUNT(*) FROM network_nodes n WHERE n.site_id=s.id AND ((n.node_type='leaf' AND n.allocation_type='subnet') OR n.l3_enabled=1)) subnet_count,
            (SELECT COUNT(*) FROM network_nodes n WHERE n.site_id=s.id AND n.allocation_type IN ('static_pool','dhcp_pool')) pool_count,
            (SELECT COUNT(*) FROM hosts h WHERE h.site_id=s.id) host_count,
            (SELECT COUNT(*) FROM services v JOIN hosts h ON h.id=v.host_id WHERE h.site_id=s.id) service_count
            FROM sites s LEFT JOIN address_spaces a ON a.id=s.address_space_id ORDER BY COALESCE(a.start_int,4294967295),s.sort_order,s.name");
        foreach ($sites as &$site) {
            $leaves = $this->all("SELECT n.cidr,
                ((SELECT COUNT(*) FROM network_interfaces i WHERE i.network_id=n.id)+(SELECT COUNT(*) FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id WHERE i.network_id=n.id)) assigned_count,
                (SELECT COUNT(*) FROM network_reservations r WHERE r.network_id=n.id) reserved_count
                FROM network_nodes n WHERE n.site_id=? AND ((n.node_type='leaf' AND n.allocation_type='subnet') OR n.l3_enabled=1)", [(int)$site['id']]);
            $usable = 0;
            $occupied = 0;
            foreach ($leaves as $leaf) {
                $parsed = IpMath::parseCidr($leaf['cidr']);
                $usable += max(0, (int)$parsed['usable'] - 1);
                $occupied += (int)$leaf['assigned_count'] + (int)$leaf['reserved_count'];
            }
            $site['block_count'] = (int)$site['area_count'];
            $site['usable_addresses'] = $usable;
            $site['occupied_addresses'] = $occupied;
            $site['free_addresses'] = max(0, $usable - $occupied);
            $site['utilization_percent'] = $usable ? round(100 * $occupied / $usable, 2) : 0;
            $range = $this->one('SELECT MIN(network_int) first_ip,MAX(broadcast_int) last_ip FROM network_nodes WHERE site_id=?', [(int)$site['id']]);
            if ($range && $range['last_ip'] !== null) {
                $cover = $this->coverRange((int)$range['first_ip'], (int)$range['last_ip']);
                $site['site_cidr'] = IpMath::toIp((int)$cover['network']).'/'.$cover['prefix'];
                $site['allocated_start'] = IpMath::toIp((int)$range['first_ip']);
                $site['allocated_end'] = IpMath::toIp((int)$range['last_ip']);
                $site['site_range'] = $site['allocated_start'].' – '.$site['allocated_end'];
                $site['site_cidrs'] = IpMath::rangeToCidrs((int)$range['first_ip'],(int)$range['last_ip']);
            } else {
                $site['site_cidr'] = $site['allocated_start'] = $site['allocated_end'] = $site['site_range'] = '';
                $site['site_cidrs'] = [];
            }
        }
        return $sites;
    }

    public function saveSite(array $data, ?int $id = null): array
    {
        $name = self::required($data, 'name', 'Name');
        $domain = DnsName::domain(self::required($data, 'domain_name', 'Domainname'));
        $currentAllocationMode=$id?(string)($this->one('SELECT allocation_mode FROM sites WHERE id=?',[$id])['allocation_mode']??'range'):'range';
        $allocationMode=self::text($data,'allocation_mode',$currentAllocationMode);
        $this->enum($allocationMode,['range','cidr'],'Site-Adressanordnung');
        $spaceId = (int)($data['address_space_id'] ?? 0);
        if($id&&!array_key_exists('address_space_id',$data))$spaceId=(int)($this->one('SELECT address_space_id FROM sites WHERE id=?',[$id])['address_space_id']??0);
        if (!$this->one('SELECT id FROM address_spaces WHERE id=?', [$spaceId])) {
            if ((int)$this->one('SELECT COUNT(*) value FROM address_spaces')['value'] > 0) throw new InvalidArgumentException('Ein gültiger globaler Adressraum ist erforderlich.');
            $spaceId = 0; // Kompatibilität für leere Alt-/Testdatenbanken; die erste Anlage ordnet diese Sites zu.
        }
        if ($id) {
            $this->transaction(function () use ($data, $name, $domain, $allocationMode, $spaceId, $id): void {
                $current=$this->one('SELECT address_space_id FROM sites WHERE id=?',[$id]);if(!$current)throw new DomainException('Site nicht gefunden.');
                if((int)($current['address_space_id']??0)!==$spaceId&&$this->one('SELECT id FROM network_nodes WHERE site_id=? LIMIT 1',[$id]))throw new DomainException('Eine belegte Site wird über die Adressplanung verschoben, damit Quell- und Zielraum gemeinsam neu berechnet werden.');
                $stmt = $this->db->prepare('UPDATE sites SET address_space_id=?, name=?, domain_name=?, allocation_mode=?, location=?, description=? WHERE id=?');
                $stmt->execute([$spaceId ?: null, $name, $domain, $allocationMode, self::text($data, 'location'), self::text($data, 'description'), $id]);
                $this->rebuildSiteDns($id, $domain);
                $this->touch($id);
            });
        } else {
            $id = $this->transaction(function () use ($data, $name, $domain, $allocationMode, $spaceId): int {
                $id = $this->entity('site');
                $sort=(int)($this->one('SELECT COALESCE(MAX(sort_order),0)+1 value FROM sites WHERE address_space_id=?',[$spaceId])['value']??1);
                $stmt = $this->db->prepare('INSERT INTO sites(id,address_space_id,sort_order,name,domain_name,allocation_mode,location,description) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$id, $spaceId ?: null, $sort, $name, $domain, $allocationMode, self::text($data, 'location'), self::text($data, 'description')]);
                return $id;
            });
        }
        return $this->one('SELECT * FROM sites WHERE id=?', [$id]) ?? throw new DomainException('Site nicht gefunden.');
    }

    /** @return list<array<string,mixed>> */
    public function blocks(?int $siteId = null): array
    {
        $where = $siteId ? 'WHERE b.site_id=?' : '';
        $rows = $this->all("SELECT b.*, s.name site_name, (SELECT COUNT(*) FROM subnets n WHERE n.block_id=b.id) subnet_count FROM address_blocks b JOIN sites s ON s.id=b.site_id $where ORDER BY b.network_int", $siteId ? [$siteId] : []);
        foreach ($rows as &$row) $row = $this->networkOutput($row);
        return $rows;
    }

    public function saveBlock(array $data, ?int $id = null): array
    {
        $cidr = IpMath::parseCidr(self::required($data, 'cidr', 'CIDR'));
        $siteId = (int) ($data['site_id'] ?? 0);
        if (!$this->one('SELECT id FROM sites WHERE id=?', [$siteId])) throw new InvalidArgumentException('Site ist ungültig.');
        $overlap = $this->one('SELECT id,cidr FROM address_blocks WHERE id<>? AND network_int<=? AND broadcast_int>=? LIMIT 1', [$id ?? 0, $cidr['broadcast'], $cidr['network']]);
        if ($overlap) throw new DomainException('Adressbereich überlappt mit ' . $overlap['cidr'] . '.');
        if($virtual=$this->one('SELECT name FROM virtual_networks WHERE network_int<=? AND broadcast_int>=? LIMIT 1',[$cidr['broadcast'],$cidr['network']]))throw new DomainException('Adressbereich überlappt mit dem virtuellen Netz '.$virtual['name'].'.');
        $values = [$siteId, self::required($data, 'name', 'Name'), $cidr['cidr'], $cidr['network'], $cidr['broadcast'], $cidr['prefix'], self::text($data, 'description')];
        if ($id) {
            $current = $this->one('SELECT id FROM subnets WHERE block_id=? AND (network_int<? OR broadcast_int>?) LIMIT 1', [$id, $cidr['network'], $cidr['broadcast']]);
            if ($current) throw new DomainException('Der neue Bereich würde bestehende Subnetze ausschließen.');
            $stmt = $this->db->prepare('UPDATE address_blocks SET site_id=?,name=?,cidr=?,network_int=?,broadcast_int=?,prefix=?,description=? WHERE id=?');
            $stmt->execute([...$values, $id]);
            $this->touch($id);
        } else {
            $id = $this->transaction(function () use ($values): int {
                $id = $this->entity('block');
                $this->db->prepare('INSERT INTO address_blocks(id,site_id,name,cidr,network_int,broadcast_int,prefix,description) VALUES (?,?,?,?,?,?,?,?)')->execute([$id, ...$values]);
                return $id;
            });
        }
        return $this->networkOutput($this->one('SELECT * FROM address_blocks WHERE id=?', [$id]) ?? throw new DomainException('Bereich nicht gefunden.'));
    }

    /** @return list<array<string,mixed>> */
    public function subnets(?int $blockId = null): array
    {
        $where = $blockId ? 'WHERE n.block_id=?' : '';
        $rows = $this->all("SELECT n.*, b.name block_name, b.site_id, s.name site_name,
            (SELECT COUNT(*) FROM interfaces i WHERE i.subnet_id=n.id) assigned_count,
            (SELECT COUNT(*) FROM reservations r WHERE r.subnet_id=n.id) reserved_count
            FROM subnets n JOIN address_blocks b ON b.id=n.block_id JOIN sites s ON s.id=b.site_id $where ORDER BY n.network_int", $blockId ? [$blockId] : []);
        foreach ($rows as &$row) {
            $row = $this->networkOutput($row);
            $row['free_count'] = max(0, $row['usable'] - (int)$row['assigned_count'] - (int)$row['reserved_count']);
            $row['utilization'] = $row['usable'] ? round(100 * ((int)$row['assigned_count'] + (int)$row['reserved_count']) / $row['usable'], 2) : 0;
        }
        return $rows;
    }

    public function saveSubnet(array $data, ?int $id = null): array
    {
        $cidr = IpMath::parseCidr(self::required($data, 'cidr', 'CIDR'));
        $blockId = (int) ($data['block_id'] ?? 0);
        $block = $this->one('SELECT * FROM address_blocks WHERE id=?', [$blockId]);
        if (!$block) throw new InvalidArgumentException('Adressbereich ist ungültig.');
        if ($cidr['network'] < $block['network_int'] || $cidr['broadcast'] > $block['broadcast_int']) throw new DomainException('Subnetz liegt nicht vollständig im Adressbereich.');
        $overlap = $this->one('SELECT id,cidr FROM subnets WHERE id<>? AND network_int<=? AND broadcast_int>=? LIMIT 1', [$id ?? 0, $cidr['broadcast'], $cidr['network']]);
        if ($overlap) throw new DomainException('Subnetz überlappt mit ' . $overlap['cidr'] . '.');
        $gateway = null;
        if (self::text($data, 'gateway') !== '') {
            $gateway = IpMath::toInt(self::text($data, 'gateway'));
            if (!IpMath::isUsable($gateway, $cidr)) throw new InvalidArgumentException('Gateway ist keine nutzbare Adresse des Subnetzes.');
        }
        $vlan = self::intOrNull($data, 'vlan_id');
        if ($vlan !== null && ($vlan < 1 || $vlan > 4094)) throw new InvalidArgumentException('VLAN-ID muss zwischen 1 und 4094 liegen.');
        $values = [$blockId, self::required($data, 'name', 'Name'), $cidr['cidr'], $cidr['network'], $cidr['broadcast'], $cidr['prefix'], $gateway, $vlan, self::text($data, 'vrf_name'), self::text($data, 'description')];
        if ($id) {
            $used = $this->one('SELECT ip_int FROM interfaces WHERE subnet_id=? AND (ip_int<? OR ip_int>?) UNION ALL SELECT ip_int FROM reservations WHERE subnet_id=? AND (ip_int<? OR ip_int>?) LIMIT 1', [$id, $cidr['first_usable'], $cidr['last_usable'], $id, $cidr['first_usable'], $cidr['last_usable']]);
            if ($used) throw new DomainException('Der neue CIDR würde bestehende Belegungen ausschließen.');
            $this->db->prepare('UPDATE subnets SET block_id=?,name=?,cidr=?,network_int=?,broadcast_int=?,prefix=?,gateway_int=?,vlan_id=?,vrf_name=?,description=? WHERE id=?')->execute([...$values, $id]);
            $this->touch($id);
        } else {
            $id = $this->transaction(function () use ($values): int {
                $id = $this->entity('subnet');
                $this->db->prepare('INSERT INTO subnets(id,block_id,name,cidr,network_int,broadcast_int,prefix,gateway_int,vlan_id,vrf_name,description) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, ...$values]);
                return $id;
            });
        }
        return $this->subnet($id);
    }

    public function subnet(int $id): array
    {
        foreach ($this->subnets() as $subnet) if ((int)$subnet['id'] === $id) return $subnet;
        throw new DomainException('Subnetz nicht gefunden.');
    }

    /** @return array{proposals:list<array<string,mixed>>,remaining:int} */
    public function planSubnets(int $blockId, array $requests): array
    {
        $block = $this->one('SELECT * FROM address_blocks WHERE id=?', [$blockId]);
        if (!$block) throw new InvalidArgumentException('Adressbereich ist ungültig.');
        $items = [];
        foreach ($requests as $index => $request) {
            $name = self::required($request, 'name', 'Subnetzname');
            $prefix = isset($request['prefix']) && $request['prefix'] !== '' ? (int)$request['prefix'] : IpMath::prefixForHosts((int)($request['hosts'] ?? 0));
            if ($prefix < (int)$block['prefix'] || $prefix > 32) throw new InvalidArgumentException("Präfix für $name passt nicht in den Adressbereich.");
            $items[] = ['name' => $name, 'prefix' => $prefix, 'size' => 2 ** (32 - $prefix), 'order' => $index];
        }
        usort($items, fn($a, $b) => $b['size'] <=> $a['size']);
        $occupied = array_map(fn($row) => [(int)$row['network_int'], (int)$row['broadcast_int']], $this->all('SELECT network_int,broadcast_int FROM subnets WHERE block_id=? ORDER BY network_int', [$blockId]));
        $proposals = [];
        foreach ($items as $item) {
            $candidate = (int)$block['network_int'];
            $placed = null;
            while ($candidate + $item['size'] - 1 <= (int)$block['broadcast_int']) {
                $candidate = (int)(ceil($candidate / $item['size']) * $item['size']);
                $end = $candidate + $item['size'] - 1;
                $collision = null;
                foreach ($occupied as $range) if ($range[0] <= $end && $range[1] >= $candidate) { $collision = $range; break; }
                if (!$collision) { $placed = [$candidate, $end]; break; }
                $candidate = $collision[1] + 1;
            }
            if (!$placed) throw new DomainException('Nicht genügend zusammenhängender Platz für ' . $item['name'] . '.');
            $occupied[] = $placed;
            $cidr = IpMath::toIp($placed[0]) . '/' . $item['prefix'];
            $proposals[] = ['name' => $item['name'], 'cidr' => $cidr, 'prefix' => $item['prefix'], 'usable' => IpMath::parseCidr($cidr)['usable'], 'order' => $item['order']];
        }
        usort($proposals, fn($a, $b) => $a['order'] <=> $b['order']);
        foreach ($proposals as &$proposal) unset($proposal['order']);
        $used = array_sum(array_map(fn($r) => $r[1] - $r[0] + 1, $occupied));
        return ['proposals' => $proposals, 'remaining' => ((int)$block['broadcast_int'] - (int)$block['network_int'] + 1) - $used];
    }

    public function applySubnetPlan(int $blockId, array $requests): array
    {
        return $this->transaction(function () use ($blockId, $requests): array {
            $plan = $this->planSubnets($blockId, $requests);
            $created = [];
            foreach ($plan['proposals'] as $proposal) $created[] = $this->saveSubnet(['block_id'=>$blockId, 'name'=>$proposal['name'], 'cidr'=>$proposal['cidr']]);
            return $created;
        });
    }

    /** @return list<array<string,mixed>> */
    public function hosts(array $filters = []): array
    {
        $params = [];
        $where = [];
        if (!empty($filters['site_id'])) { $where[] = 'h.site_id=?'; $params[] = (int)$filters['site_id']; }
        if (!empty($filters['status'])) { $where[] = 'h.status=?'; $params[] = $filters['status']; }
        if (!empty($filters['q'])) { $where[] = '(h.name LIKE ? OR h.hostname LIKE ? OR h.description LIKE ? OR EXISTS(SELECT 1 FROM dns_names d WHERE d.host_id=h.id AND d.fqdn LIKE ?))'; $term='%'.$filters['q'].'%'; array_push($params,$term,$term,$term,$term); }
        $clause = $where ? 'WHERE '.implode(' AND ', $where) : '';
        $rows = $this->all("SELECT h.*, s.name site_name, s.domain_name, d.fqdn, (SELECT COUNT(*) FROM network_interfaces i WHERE i.host_id=h.id) interface_count, (SELECT COUNT(*) FROM services v WHERE v.host_id=h.id) service_count FROM hosts h JOIN sites s ON s.id=h.site_id LEFT JOIN dns_names d ON d.host_id=h.id AND d.kind='primary' $clause ORDER BY h.name", $params);
        foreach ($rows as &$row) {
            $row['interfaces'] = $this->interfaces((int)$row['id']);
            $row['aliases'] = $this->hostAliases((int)$row['id']);
        }
        return $rows;
    }

    public function saveHost(array $data, ?int $id = null): array
    {
        $siteId = (int)($data['site_id'] ?? 0);
        $site = $this->one('SELECT id,domain_name FROM sites WHERE id=?', [$siteId]);
        if (!$site) throw new InvalidArgumentException('Site ist ungültig.');
        $type = self::text($data, 'type', 'server');
        $status = self::text($data, 'status', 'planned');
        $primary = DnsName::primary(self::required($data, 'hostname', 'Hostname'), (string)$site['domain_name']);
        $hostname = $primary['hostname'];
        $this->enum($type, ['server','vm','network','client','appliance','other'], 'Hosttyp');
        $this->enum($status, ['planned','active','degraded','inactive'], 'Status');
        $aliases = $this->aliasesFromData($data, $id);
        $isRouter=array_key_exists('is_router',$data)?(!empty($data['is_router'])?1:0):($id?(int)($this->one('SELECT is_router FROM hosts WHERE id=?',[$id])['is_router']??0):0);
        $name=self::required($data,'name','Name');
        $duplicate=$this->one('SELECT id FROM hosts WHERE site_id=? AND lower(name)=lower(?) AND id<>? LIMIT 1',[$siteId,$name,$id??0]);
        if($duplicate)throw new DomainException('In dieser Site existiert bereits ein Host namens '.$name.'.');
        $values = [$siteId,$name,$hostname,$type,$status,self::text($data,'description'),self::text($data,'notes'),$isRouter];
        if ($id) {
            $this->transaction(function () use ($values, $id, $hostname, $aliases, $siteId): void {
                $this->db->prepare('UPDATE hosts SET site_id=?,name=?,hostname=?,type=?,status=?,description=?,notes=?,is_router=? WHERE id=?')->execute([...$values,$id]);
                $this->syncHostDns($id, $hostname, $siteId, $aliases);
                $this->touch($id);
            });
        } else {
            $id = $this->transaction(function () use ($values, $hostname, $aliases, $siteId): int { $id=$this->entity('host'); $this->db->prepare('INSERT INTO hosts(id,site_id,name,hostname,type,status,description,notes,is_router) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id,...$values]); $this->syncHostDns($id, $hostname, $siteId, $aliases); return $id; });
        }
        foreach ($this->hosts() as $host) if ((int)$host['id'] === $id) return $host;
        throw new DomainException('Host nicht gefunden.');
    }

    public function interfaces(?int $hostId = null): array
    {
        $where = $hostId ? 'WHERE i.host_id=?' : '';
        $rows = $this->all("SELECT i.*, i.network_id subnet_id, h.name host_name,h.site_id,s.name site_name, n.cidr subnet_cidr, n.parent_id block_id,p.name pool_name,p.allocation_type pool_type,COALESCE(d.fqdn,i.dns_name) resolved_dns_name, EXISTS(SELECT 1 FROM gateway_bindings g WHERE g.interface_id=i.id) is_gateway FROM network_interfaces i JOIN hosts h ON h.id=i.host_id JOIN sites s ON s.id=h.site_id JOIN network_nodes n ON n.id=i.network_id LEFT JOIN network_nodes p ON p.id=i.pool_id LEFT JOIN dns_names d ON d.network_interface_id=i.id AND d.kind='interface_alias' $where ORDER BY i.ip_int", $hostId ? [$hostId] : []);
        foreach ($rows as &$row) { $row['ip'] = IpMath::toIp((int)$row['ip_int']); $row['ips']=[$row['ip'],...array_map(fn($address)=>IpMath::toIp((int)$address['ip_int']),$this->all('SELECT ip_int FROM network_interface_addresses WHERE interface_id=? ORDER BY ip_int',[(int)$row['id']]))];$row['secondary_ips']=array_slice($row['ips'],1); $row['dns_name'] = $row['resolved_dns_name']; unset($row['resolved_dns_name']); }
        return $rows;
    }

    public function saveInterface(array $data, ?int $id = null): array
    {
        $hostId=(int)($data['host_id']??0); $subnetId=(int)($data['network_id']??$data['subnet_id']??0);
        $host=$this->one('SELECT * FROM hosts WHERE id=?',[$hostId]); $subnet=$this->one('SELECT n.*,a.routing_domain FROM network_nodes n JOIN address_spaces a ON a.id=n.address_space_id WHERE n.id=?',[$subnetId]);
        if (!$host || !$subnet || !(($subnet['node_type']==='leaf'&&($subnet['allocation_type']??'subnet')==='subnet')||!empty($subnet['l3_enabled']))) throw new InvalidArgumentException('Host oder Layer-3-Subnetz ist ungültig.');
        $sameSite=(int)$host['site_id']===(int)$subnet['site_id'];$existing=$id?$this->one('SELECT host_id,network_id FROM network_interfaces WHERE id=?',[$id]):null;$keepsLegacyCrossSite=$existing&&(int)$existing['host_id']===$hostId&&(int)$existing['network_id']===$subnetId;
        if(!$sameSite&&!$keepsLegacyCrossSite&&empty($data['allow_cross_site']))throw new DomainException('Interface und Host müssen derselben Site angehören. Für siteübergreifende Router-Verbindungen verwende ein globales VPN-Netz.');
        $network=IpMath::parseCidr($subnet['cidr']);
        $poolId=(int)($data['pool_id']??0)?:null;
        $pool=$poolId?$this->one("SELECT *,(network_int+COALESCE(pool_start_offset,0)) range_start,(network_int+COALESCE(pool_end_offset,broadcast_int-network_int)) range_end FROM network_nodes WHERE id=? AND allocation_type IN ('static_pool','dhcp_pool')",[$poolId]):null;
        if($poolId&&!$pool)throw new DomainException('Gewählter Adresspool wurde nicht gefunden.');
        if($pool&&($this->l3NetworkId($pool)!==$subnetId||(int)$pool['site_id']!==(int)$subnet['site_id']))throw new DomainException('Adresspool gehört nicht zum gewählten L3-Subnetz.');
        $ipInputs=$this->ipInputs($data);$gatewayRequested=!empty($data['is_gateway']);
        if(!$ipInputs&&(int)($host['is_router']??0)){$ipInputs=[IpMath::toIp((int)($subnet['gateway_int']??$network['first_usable']))];$gatewayRequested=true;}
        if(!$ipInputs){[$automaticIp,$automaticPool]=$this->nextFreeInterfaceAddress($subnet,$pool,$id);if($automaticIp===null)throw new DomainException('Im gewählten Subnetz ist keine freie IP-Adresse verfügbar.');$ipInputs=[IpMath::toIp($automaticIp)];if($automaticPool){$pool=$automaticPool;$poolId=(int)$automaticPool['id'];}}
        $ips=array_map(fn($value)=>IpMath::toInt($value),$ipInputs);$ip=$ips[0];
        foreach($ips as$address)if(!IpMath::isUsable($address,$network))throw new InvalidArgumentException('IP-Adresse '.IpMath::toIp($address).' ist im Subnetz nicht nutzbar.');
        if((int)$subnet['gateway_int']===$ip&&!$gatewayRequested) throw new DomainException('IP-Adresse ist das Gateway des Subnetzes.');if($gatewayRequested&&!(int)($host['is_router']??0))throw new DomainException('Nur Router-Interfaces können ein Gateway übernehmen.');if($pool&&((int)$pool['range_start']>$ip||(int)$pool['range_end']<$ip))throw new DomainException('IP-Adresse oder Adresspool gehört nicht zum gewählten L3-Subnetz.');if(!$pool&&!$gatewayRequested){foreach($this->all("SELECT * FROM network_nodes WHERE site_id=? AND allocation_type IN ('static_pool','dhcp_pool')",[$subnet['site_id']])as$candidate){$start=(int)$candidate['network_int']+(int)($candidate['pool_start_offset']??0);$end=(int)$candidate['network_int']+(int)($candidate['pool_end_offset']??((int)$candidate['broadcast_int']-(int)$candidate['network_int']));if($this->l3NetworkId($candidate)===$subnetId&&$ip>=$start&&$ip<=$end){$pool=$candidate;$pool['range_start']=$start;$pool['range_end']=$end;$poolId=(int)$candidate['id'];break;}}}if($pool&&$pool['allocation_type']==='dhcp_pool'&&empty($data['confirm_dhcp_overlap']))throw new DomainException('Die IP liegt in einem DHCP-Pool. Vergabe ausdrücklich bestätigen.');
        foreach($ips as$address){if($pool&&((int)$pool['range_start']>$address||(int)$pool['range_end']<$address))throw new DomainException('IP-Adresse '.IpMath::toIp($address).' gehört nicht zum gewählten Adresspool.');$this->assertAddressAvailable($address,$id,null);}
        $mac=self::text($data,'mac_address');
        if ($mac!=='' && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',$mac)) throw new InvalidArgumentException('MAC-Adresse ist ungültig.');
        $dns=self::text($data,'dns_name'); if($dns!=='')$dns=DnsName::domain($dns);$interfaceName=self::required($data,'name','Interfacename');if($this->one('SELECT id FROM vpn_interfaces WHERE router_id=? AND name=? LIMIT 1',[$hostId,$interfaceName]))throw new DomainException('Der Router besitzt bereits ein VPN-Interface mit diesem Namen.');
        $values=[$hostId,$subnetId,$poolId,$interfaceName,$mac===''?null:strtolower(str_replace('-',':',$mac)),$ip,$dns];
        $id=$this->transaction(function()use($id,$values,$hostId,$dns,$gatewayRequested,$subnetId,$ip,$subnet,$ips):int{
            if ($id) $this->db->prepare('UPDATE network_interfaces SET host_id=?,network_id=?,pool_id=?,name=?,mac_address=?,ip_int=?,dns_name=? WHERE id=?')->execute([...$values,$id]);
            else { $this->db->prepare('INSERT INTO network_interfaces(host_id,network_id,pool_id,name,mac_address,ip_int,dns_name) VALUES (?,?,?,?,?,?,?)')->execute($values); $id=(int)$this->db->lastInsertId(); }
            $this->db->prepare('DELETE FROM network_interface_addresses WHERE interface_id=?')->execute([$id]);$insert=$this->db->prepare('INSERT INTO network_interface_addresses(interface_id,ip_int) VALUES (?,?)');foreach(array_slice($ips,1)as$address)$insert->execute([$id,$address]);
            $this->db->prepare("DELETE FROM dns_names WHERE network_interface_id=? AND kind='interface_alias'")->execute([$id]);
            if($dns!=='')$this->insertDns($hostId,$id,'interface_alias',$dns,1,$dns);
            if($gatewayRequested){$offset=$ip-(int)$subnet['network_int'];$this->db->prepare('INSERT INTO gateway_bindings(network_id,interface_id,address_offset) VALUES (?,?,?) ON CONFLICT(network_id) DO UPDATE SET interface_id=excluded.interface_id,address_offset=excluded.address_offset')->execute([$subnetId,$id,$offset]);$this->db->prepare('UPDATE network_nodes SET gateway_int=? WHERE id=?')->execute([$ip,$subnetId]);}
            return $id;
        });
        foreach($this->interfaces($hostId)as$interface)if((int)$interface['id']===$id)return$interface;
        throw new DomainException('Interface nicht gefunden.');
    }

    public function routers(): array
    {
        $result=[];foreach($this->hosts()as$host)if((int)($host['is_router']??0)===1){$host['routes']=$this->routerRoutes((int)$host['id']);$host['gateway_count']=(int)($this->one('SELECT COUNT(*) value FROM gateway_bindings g JOIN network_interfaces i ON i.id=g.interface_id WHERE i.host_id=?',[$host['id']])['value']??0);$result[]=$host;}return$result;
    }

    public function saveRouter(array $data,?int$id=null):array{$data['is_router']=1;$data['type']='network';return$this->saveHost($data,$id);}

    public function routerRoutes(int$routerId):array
    {
        $router=$this->one('SELECT id FROM hosts WHERE id=? AND is_router=1',[$routerId]);if(!$router)throw new DomainException('Router nicht gefunden.');$rows=[];
        foreach($this->all('SELECT i.id egress_interface_id,i.name interface_name,i.ip_int,n.id destination_network_id,n.name destination_name,n.cidr destination_cidr FROM network_interfaces i JOIN network_nodes n ON n.id=i.network_id WHERE i.host_id=? ORDER BY n.prefix DESC,n.network_int',[$routerId])as$r){$r['id']='connected-'.$r['egress_interface_id'];$r['route_type']='connected';$r['next_hop']='direkt';$r['metric']=0;$rows[]=$r;}
        foreach($this->all("SELECT i.id vpn_interface_id,i.name interface_name,i.ip_int,v.id virtual_network_id,v.name destination_name,v.cidr destination_cidr FROM vpn_interfaces i JOIN virtual_networks v ON v.id=i.virtual_network_id WHERE i.router_id=? ORDER BY v.prefix DESC,v.network_int",[$routerId])as$r){$r['id']='vpn-connected-'.$r['vpn_interface_id'];$r['destination_network_id']=null;$r['route_type']='connected';$r['next_hop']='VPN direkt';$r['metric']=0;$rows[]=$r;}
        foreach($this->all('SELECT r.*,i.name interface_name,n.name destination_name FROM static_routes r JOIN network_interfaces i ON i.id=r.egress_interface_id LEFT JOIN network_nodes n ON n.id=r.destination_network_id WHERE r.router_id=? ORDER BY CASE WHEN r.destination_cidr="0.0.0.0/0" THEN 1 ELSE 0 END,r.destination_cidr,r.metric',[$routerId])as$r){$r['route_type']='static';$r['next_hop']=IpMath::toIp((int)$r['next_hop_int']);$rows[]=$r;}return$rows;
    }

    public function saveStaticRoute(array$data,?int$id=null):array
    {
        $routerId=(int)($data['router_id']??0);if(!$this->one('SELECT id FROM hosts WHERE id=? AND is_router=1',[$routerId]))throw new InvalidArgumentException('Router ist ungültig.');$destination=IpMath::parseCidr(self::required($data,'destination_cidr','Ziel-CIDR'))['cidr'];$interfaceId=(int)($data['egress_interface_id']??0);$interface=$this->one('SELECT i.*,n.network_int,n.broadcast_int,n.cidr FROM network_interfaces i JOIN network_nodes n ON n.id=i.network_id WHERE i.id=? AND i.host_id=?',[$interfaceId,$routerId]);if(!$interface)throw new InvalidArgumentException('Ausgangsinterface gehört nicht zum Router.');$nextHop=IpMath::toInt(self::required($data,'next_hop','Next Hop'));if(!IpMath::isUsable($nextHop,IpMath::parseCidr($interface['cidr']))||$nextHop===(int)$interface['ip_int'])throw new DomainException('Next Hop ist über das Ausgangsinterface nicht erreichbar.');$metric=(int)($data['metric']??10);if($metric<0||$metric>65535)throw new InvalidArgumentException('Metrik muss zwischen 0 und 65535 liegen.');$networkId=(int)($data['destination_network_id']??0)?:null;if($networkId&&!$this->one("SELECT id FROM network_nodes WHERE id=? AND ((node_type='leaf' AND allocation_type='subnet') OR l3_enabled=1)",[$networkId]))throw new InvalidArgumentException('Zielnetz ist ungültig.');$values=[$routerId,$destination,$networkId,$interfaceId,$nextHop,$metric,self::text($data,'description')];if($id)$this->db->prepare('UPDATE static_routes SET router_id=?,destination_cidr=?,destination_network_id=?,egress_interface_id=?,next_hop_int=?,metric=?,description=? WHERE id=?')->execute([...$values,$id]);else{$this->db->prepare('INSERT INTO static_routes(router_id,destination_cidr,destination_network_id,egress_interface_id,next_hop_int,metric,description) VALUES (?,?,?,?,?,?,?)')->execute($values);$id=(int)$this->db->lastInsertId();}foreach($this->routerRoutes($routerId)as$route)if((string)$route['id']===(string)$id)return$route;throw new DomainException('Route nicht gefunden.');
    }

    public function routeSuggestions(int$routerId):array
    {
        if(!$this->one('SELECT id FROM hosts WHERE id=? AND is_router=1',[$routerId]))throw new DomainException('Router nicht gefunden.');
        $routerInterfaces=$this->all('SELECT i.*,n.cidr FROM network_interfaces i JOIN network_nodes n ON n.id=i.network_id WHERE i.host_id=?',[$routerId]);
        $direct=array_fill_keys(array_map(fn($i)=>(int)$i['network_id'],$routerInterfaces),true);
        $all=$this->all('SELECT i.*,n.cidr FROM network_interfaces i JOIN hosts h ON h.id=i.host_id JOIN network_nodes n ON n.id=i.network_id WHERE h.is_router=1');$byNetwork=[];
        foreach($all as$i)$byNetwork[(int)$i['network_id']][]=$i;$adj=[];
        foreach($byNetwork as$items)foreach($items as$a)foreach($items as$b)if((int)$a['host_id']!==(int)$b['host_id'])$adj[(int)$a['host_id']][]=['router'=>(int)$b['host_id'],'egress'=>(int)$a['id'],'next_hop'=>(int)$b['ip_int']];
        $queue=[[$routerId,null,0]];$seen=[$routerId=>true];$paths=[];
        while($queue){[$current,$first,$depth]=array_shift($queue);foreach($adj[$current]??[]as$edge)if(!isset($seen[$edge['router']])){$seen[$edge['router']]=true;$pathFirst=$first??$edge;$paths[$edge['router']]=[$pathFirst,$depth+1];$queue[]=[$edge['router'],$pathFirst,$depth+1];}}
        $existing=array_fill_keys(array_column($this->all('SELECT destination_cidr FROM static_routes WHERE router_id=?',[$routerId]),'destination_cidr'),true);$suggestions=[];
        foreach($all as$i){$networkId=(int)$i['network_id'];if(isset($direct[$networkId])||!isset($paths[(int)$i['host_id']]))continue;$network=$this->one("SELECT id,name,cidr FROM network_nodes WHERE id=? AND ((node_type='leaf' AND allocation_type='subnet') OR l3_enabled=1)",[$networkId]);if(!$network||isset($existing[$network['cidr']]))continue;[$first,$hops]=$paths[(int)$i['host_id']];$suggestions[$networkId]=['router_id'=>$routerId,'destination_network_id'=>$networkId,'destination_name'=>$network['name'],'destination_cidr'=>$network['cidr'],'egress_interface_id'=>$first['egress'],'next_hop'=>IpMath::toIp($first['next_hop']),'metric'=>$hops*10,'description'=>'Vorschlag über geplante Routertopologie'];}
        return array_values($suggestions);
    }

    /** @return list<array<string,mixed>> */
    public function services(array $filters=[]): array
    {
        $params=[];$where=[];
        if (!empty($filters['host_id'])) {$where[]='v.host_id=?';$params[]=(int)$filters['host_id'];}
        if (!empty($filters['status'])) {$where[]='v.status=?';$params[]=$filters['status'];}
        if (!empty($filters['q'])) {$where[]='(v.name LIKE ? OR v.url LIKE ? OR h.name LIKE ?)';$term='%'.$filters['q'].'%';array_push($params,$term,$term,$term);}
        $clause=$where?'WHERE '.implode(' AND ',$where):'';
        return $this->all("SELECT v.*,h.name host_name,h.site_id FROM services v JOIN hosts h ON h.id=v.host_id $clause ORDER BY h.name,v.name",$params);
    }

    public function saveService(array $data, ?int $id=null): array
    {
        $hostId=(int)($data['host_id']??0); if(!$this->one('SELECT id FROM hosts WHERE id=?',[$hostId])) throw new InvalidArgumentException('Host ist ungültig.');
        $protocol=self::text($data,'protocol','tcp'); $environment=self::text($data,'environment','production');$status=self::text($data,'status','planned');
        $this->enum($protocol,['tcp','udp','icmp','other'],'Protokoll');$this->enum($environment,['production','test','development','other'],'Umgebung');$this->enum($status,['planned','active','degraded','inactive'],'Status');
        $start=self::intOrNull($data,'port_start');$end=self::intOrNull($data,'port_end');
        if (($start!==null && ($start<1||$start>65535))||($end!==null&&($end<1||$end>65535))||($start!==null&&$end!==null&&$end<$start)) throw new InvalidArgumentException('Port oder Portbereich ist ungültig.');
        $values=[$hostId,self::required($data,'name','Name'),$protocol,$start,$end,self::text($data,'url'),$environment,$status,self::text($data,'description'),self::text($data,'notes')];
        if($id){$this->db->prepare('UPDATE services SET host_id=?,name=?,protocol=?,port_start=?,port_end=?,url=?,environment=?,status=?,description=?,notes=? WHERE id=?')->execute([...$values,$id]);$this->touch($id);}
        else{$id=$this->transaction(function()use($values):int{$id=$this->entity('service');$this->db->prepare('INSERT INTO services(id,host_id,name,protocol,port_start,port_end,url,environment,status,description,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,...$values]);return $id;});}
        return $this->one('SELECT * FROM services WHERE id=?',[$id])??throw new DomainException('Dienst nicht gefunden.');
    }

    public function reservations(?int $subnetId=null): array
    {
        $where=$subnetId?'WHERE r.network_id=? OR r.pool_id=?':'';$rows=$this->all("SELECT r.*,r.network_id subnet_id,n.cidr subnet_cidr,p.name pool_name,p.allocation_type pool_type FROM network_reservations r JOIN network_nodes n ON n.id=r.network_id LEFT JOIN network_nodes p ON p.id=r.pool_id $where ORDER BY r.ip_int",$subnetId?[$subnetId,$subnetId]:[]);
        foreach($rows as &$row)$row['ip']=IpMath::toIp((int)$row['ip_int']); return $rows;
    }

    public function saveReservation(array $data, ?int $id=null): array
    {
        $subnetId=(int)($data['network_id']??$data['subnet_id']??0);$subnet=$this->one('SELECT n.*,a.routing_domain FROM network_nodes n JOIN address_spaces a ON a.id=n.address_space_id WHERE n.id=?',[$subnetId]);if(!$subnet)throw new InvalidArgumentException('Subnetz ist ungültig.');$poolId=(int)($data['pool_id']??0)?:null;if(in_array($subnet['allocation_type']??'subnet',['static_pool','dhcp_pool'],true)){$poolId=(int)$subnet['id'];for($parent=$subnet['parent_id'];$parent!==null;){$candidate=$this->one('SELECT n.*,a.routing_domain FROM network_nodes n JOIN address_spaces a ON a.id=n.address_space_id WHERE n.id=?',[$parent]);if(!$candidate)break;if(!empty($candidate['l3_enabled'])){$subnet=$candidate;$subnetId=(int)$candidate['id'];break;}$parent=$candidate['parent_id'];}}if(!(($subnet['node_type']==='leaf'&&($subnet['allocation_type']??'subnet')==='subnet')||!empty($subnet['l3_enabled'])))throw new InvalidArgumentException('Layer-3-Subnetz ist ungültig.');
        $ip=IpMath::toInt(self::required($data,'ip','IP-Adresse'));if(!IpMath::isUsable($ip,IpMath::parseCidr($subnet['cidr'])))throw new InvalidArgumentException('IP-Adresse ist im Subnetz nicht nutzbar.');
        if((int)$subnet['gateway_int']===$ip)throw new DomainException('IP-Adresse ist das Gateway.');if($this->one('SELECT id FROM network_interfaces WHERE ip_int=? LIMIT 1',[$ip])||$this->one('SELECT id FROM network_interface_addresses WHERE ip_int=? LIMIT 1',[$ip]))throw new DomainException('IP-Adresse ist bereits einem Host zugewiesen.');if($this->one('SELECT id FROM network_reservations WHERE ip_int=? AND id<>? LIMIT 1',[$ip,$id??0]))throw new DomainException('IP-Adresse ist bereits reserviert.');
        $type=self::text($data,'reservation_type','static');$this->enum($type,['static','dhcp_reservation','dhcp_exclusion'],'Reservierungstyp');if($poolId){$pool=$this->one("SELECT *,(network_int+COALESCE(pool_start_offset,0)) range_start,(network_int+COALESCE(pool_end_offset,broadcast_int-network_int)) range_end FROM network_nodes WHERE id=? AND allocation_type IN ('static_pool','dhcp_pool')",[$poolId]);if(!$pool||$this->l3NetworkId($pool)!==$subnetId||$ip<(int)$pool['range_start']||$ip>(int)$pool['range_end'])throw new DomainException('Reservierung liegt nicht im gewählten Pool oder L3-Subnetz.');if($pool['allocation_type']==='dhcp_pool'&&$type==='static')$type='dhcp_reservation';if($pool['allocation_type']==='static_pool'&&$type!=='static')throw new DomainException('DHCP-Reservierungen und -Ausschlüsse benötigen einen DHCP-Pool.');}elseif($type!=='static')throw new DomainException('DHCP-Reservierungen und -Ausschlüsse benötigen einen DHCP-Pool.');$values=[$subnetId,$poolId,$type,$ip,self::required($data,'label','Bezeichnung'),self::text($data,'reason')];
        if($id)$this->db->prepare('UPDATE network_reservations SET network_id=?,pool_id=?,reservation_type=?,ip_int=?,label=?,reason=? WHERE id=?')->execute([...$values,$id]);
        else{$this->db->prepare('INSERT INTO network_reservations(network_id,pool_id,reservation_type,ip_int,label,reason) VALUES (?,?,?,?,?,?)')->execute($values);$id=(int)$this->db->lastInsertId();}
        return $this->one('SELECT * FROM network_reservations WHERE id=?',[$id])??throw new DomainException('Reservierung nicht gefunden.');
    }

    /** @return list<array<string,mixed>> */
    public function virtualNetworks():array
    {
        $rows=$this->all("SELECT v.*,h.name owner_name,h.site_id owner_site_id,s.name owner_site_name,
            (SELECT COUNT(*) FROM vpn_interfaces i WHERE i.virtual_network_id=v.id) interface_count
            FROM virtual_networks v LEFT JOIN hosts h ON h.id=v.owner_host_id LEFT JOIN sites s ON s.id=h.site_id
            ORDER BY CASE v.network_type WHEN 'vpn' THEN 0 ELSE 1 END,v.network_int,v.name");
        foreach($rows as&$row){$row['gateway']=$row['gateway_int']===null?'':IpMath::toIp((int)$row['gateway_int']);$row['usable']=IpMath::parseCidr($row['cidr'])['usable'];$row['driver']=$row['driver']?:($row['network_type']==='docker_bridge'?'bridge':'');}
        return$rows;
    }

    public function saveVirtualNetwork(array$data,?int$id=null):array
    {
        $type=self::text($data,'network_type','vpn');$this->enum($type,['vpn','docker_bridge'],'Virtueller Netztyp');$name=self::required($data,'name','Name');$cidr=IpMath::parseCidr(self::required($data,'cidr','CIDR'));if((int)$cidr['prefix']===32)throw new InvalidArgumentException('Virtuelle Netze müssen mindestens zwei Adressen umfassen; /32 ist nicht zulässig.');
        $owner=null;$gateway=null;$driver='';if($type==='docker_bridge'){$owner=(int)($data['owner_host_id']??0);if(!$this->one('SELECT id FROM hosts WHERE id=?',[$owner]))throw new InvalidArgumentException('Docker-Besitzer ist ungültig.');$gatewayText=self::text($data,'gateway');$gateway=$gatewayText===''?(int)$cidr['first_usable']:IpMath::toInt($gatewayText);if(!IpMath::isUsable($gateway,$cidr))throw new DomainException('Docker-Gateway ist in diesem CIDR nicht nutzbar.');$driver='bridge';}
        $this->assertVirtualNetworkAvailable($cidr,$type,$owner,$id);
        $values=[$type,$name,$cidr['cidr'],$cidr['network'],$cidr['broadcast'],$cidr['prefix'],$gateway,$owner,$driver,self::text($data,'description')];
        if($id){$current=$this->one('SELECT * FROM virtual_networks WHERE id=?',[$id]);if(!$current)throw new DomainException('Virtuelles Netz nicht gefunden.');if($current['network_type']==='vpn'&&$type!=='vpn'&&$this->one('SELECT id FROM vpn_interfaces WHERE virtual_network_id=? LIMIT 1',[$id]))throw new DomainException('VPN-Teilnehmer müssen vor einem Typwechsel entfernt werden.');$moves=[];if($current['network_type']==='vpn'&&$type==='vpn'){foreach($this->all("SELECT 'primary' address_kind,i.id,i.ip_int FROM vpn_interfaces i WHERE i.virtual_network_id=? UNION ALL SELECT 'secondary',a.id,a.ip_int FROM vpn_interface_addresses a JOIN vpn_interfaces i ON i.id=a.interface_id WHERE i.virtual_network_id=?",[$id,$id])as$address){$new=(int)$cidr['network']+((int)$address['ip_int']-(int)$current['network_int']);if(!IpMath::isUsable($new,$cidr))throw new DomainException('Der bisherige Adressoffset von '.IpMath::toIp((int)$address['ip_int']).' passt nicht in das neue VPN-CIDR.');$moves[]=[...$address,'new_int'=>$new];}}$this->transaction(function()use($values,$id,$moves):void{$this->db->prepare('UPDATE virtual_networks SET network_type=?,name=?,cidr=?,network_int=?,broadcast_int=?,prefix=?,gateway_int=?,owner_host_id=?,driver=?,description=? WHERE id=?')->execute([...$values,$id]);foreach($moves as$move){$table=$move['address_kind']==='primary'?'vpn_interfaces':'vpn_interface_addresses';$this->db->prepare("UPDATE $table SET ip_int=? WHERE id=?")->execute([-(int)$move['id']-6000000,(int)$move['id']]);}foreach($moves as$move){$table=$move['address_kind']==='primary'?'vpn_interfaces':'vpn_interface_addresses';$this->db->prepare("UPDATE $table SET ip_int=? WHERE id=?")->execute([(int)$move['new_int'],(int)$move['id']]);}$this->touch($id);});}
        else{$id=$this->transaction(function()use($values):int{$id=$this->entity('virtual_network');$this->db->prepare('INSERT INTO virtual_networks(id,network_type,name,cidr,network_int,broadcast_int,prefix,gateway_int,owner_host_id,driver,description) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,...$values]);return$id;});}
        return array_values(array_filter($this->virtualNetworks(),fn($row)=>(int)$row['id']===$id))[0]??throw new DomainException('Virtuelles Netz nicht gefunden.');
    }

    /** @return list<array<string,mixed>> */
    public function vpnInterfaces(?int$networkId=null,?int$routerId=null):array
    {
        $where=[];$params=[];if($networkId){$where[]='i.virtual_network_id=?';$params[]=$networkId;}if($routerId){$where[]='i.router_id=?';$params[]=$routerId;}$clause=$where?'WHERE '.implode(' AND ',$where):'';$rows=$this->all("SELECT i.*,v.name network_name,v.cidr,h.name router_name,h.site_id,s.name site_name FROM vpn_interfaces i JOIN virtual_networks v ON v.id=i.virtual_network_id JOIN hosts h ON h.id=i.router_id JOIN sites s ON s.id=h.site_id $clause ORDER BY v.name,i.ip_int",$params);foreach($rows as&$row){$row['ip']=IpMath::toIp((int)$row['ip_int']);$row['ips']=[$row['ip'],...array_map(fn($address)=>IpMath::toIp((int)$address['ip_int']),$this->all('SELECT ip_int FROM vpn_interface_addresses WHERE interface_id=? ORDER BY ip_int',[(int)$row['id']]))];$row['secondary_ips']=array_slice($row['ips'],1);}return$rows;
    }

    public function saveVpnInterface(array$data,?int$id=null):array
    {
        $networkId=(int)($data['virtual_network_id']??0);$routerId=(int)($data['router_id']??0);$network=$this->one("SELECT * FROM virtual_networks WHERE id=? AND network_type='vpn'",[$networkId]);$router=$this->one('SELECT * FROM hosts WHERE id=? AND is_router=1',[$routerId]);if(!$network||!$router)throw new InvalidArgumentException('VPN-Netz oder Router ist ungültig.');$inputs=$this->ipInputs($data);if(!$inputs)throw new InvalidArgumentException('Mindestens eine Tunnel-IP ist erforderlich.');$ips=array_map(fn($value)=>IpMath::toInt($value),$inputs);$ip=$ips[0];foreach($ips as$address){if(!IpMath::isUsable($address,IpMath::parseCidr($network['cidr'])))throw new DomainException('Tunnel-IP '.IpMath::toIp($address).' ist im VPN-Netz nicht nutzbar.');$this->assertAddressAvailable($address,null,$id);}$name=self::required($data,'name','Interfacename');
        if($this->one('SELECT id FROM network_interfaces WHERE host_id=? AND name=? LIMIT 1',[$routerId,$name]))throw new DomainException('Der Router besitzt bereits ein normales Interface mit diesem Namen.');$values=[$networkId,$routerId,$name,$ip];$id=$this->transaction(function()use($id,$values,$ips):int{if($id)$this->db->prepare('UPDATE vpn_interfaces SET virtual_network_id=?,router_id=?,name=?,ip_int=? WHERE id=?')->execute([...$values,$id]);else{$this->db->prepare('INSERT INTO vpn_interfaces(virtual_network_id,router_id,name,ip_int) VALUES (?,?,?,?)')->execute($values);$id=(int)$this->db->lastInsertId();}$this->db->prepare('DELETE FROM vpn_interface_addresses WHERE interface_id=?')->execute([$id]);$insert=$this->db->prepare('INSERT INTO vpn_interface_addresses(interface_id,ip_int) VALUES (?,?)');foreach(array_slice($ips,1)as$address)$insert->execute([$id,$address]);return$id;});return array_values(array_filter($this->vpnInterfaces(),fn($row)=>(int)$row['id']===$id))[0]??throw new DomainException('VPN-Interface nicht gefunden.');
    }

    public function deleteVirtualNetwork(int$id):void
    {
        if($this->one('SELECT id FROM vpn_interfaces WHERE virtual_network_id=? LIMIT 1',[$id]))throw new DomainException('VPN-Teilnehmer müssen vor dem Löschen entfernt werden.');if($this->one('SELECT id FROM relations WHERE source_id=? OR target_id=? LIMIT 1',[$id,$id]))throw new DomainException('Virtuelles Netz besitzt Beziehungen und kann nicht gelöscht werden.');$stmt=$this->db->prepare('DELETE FROM entities WHERE id=? AND kind=\'virtual_network\'');$stmt->execute([$id]);if(!$stmt->rowCount())throw new DomainException('Virtuelles Netz nicht gefunden.');
    }

    private function assertVirtualNetworkAvailable(array$cidr,string$type,?int$owner,?int$id):void
    {
        foreach($this->all('SELECT a.name space_name,n.name network_name,n.cidr,n.network_int,n.broadcast_int FROM network_nodes n JOIN address_spaces a ON a.id=n.address_space_id')as$network)if((int)$network['network_int']<=$cidr['broadcast']&&(int)$network['broadcast_int']>=$cidr['network'])throw new DomainException('CIDR überlappt mit '.$network['network_name'].' im dynamischen Adressraum '.$network['space_name'].'.');
        foreach($this->all('SELECT name,cidr,network_int,broadcast_int FROM address_blocks')as$block)if((int)$block['network_int']<=$cidr['broadcast']&&(int)$block['broadcast_int']>=$cidr['network'])throw new DomainException('CIDR überlappt mit dem Adressbereich '.$block['name'].'.');
        foreach($this->all('SELECT * FROM virtual_networks WHERE id<>?',[$id??0])as$other){if((int)$other['network_int']>$cidr['broadcast']||(int)$other['broadcast_int']<$cidr['network'])continue;if($type==='docker_bridge'&&$other['network_type']==='docker_bridge'&&(int)$other['owner_host_id']!==(int)$owner)continue;throw new DomainException('CIDR überlappt mit dem virtuellen Netz '.$other['name'].'.');}
    }

    private function coverRange(int$start,int$end):array
    {
        $prefix=32;while($prefix>0){$size=2**(32-$prefix);$network=intdiv($start,$size)*$size;if($network+$size-1>=$end)break;$prefix--;}$size=2**(32-$prefix);$network=intdiv($start,$size)*$size;return['network'=>$network,'broadcast'=>$network+$size-1,'prefix'=>$prefix];
    }

    public function relations(): array
    {
        return $this->all("SELECT r.*,se.kind source_kind,te.kind target_kind,
            COALESCE(sa.name,ss.name,snn.name,sb.name,sn.name,sh.name,sv.name,svirt.name) source_name,
            COALESCE(ta.name,ts.name,tnn.name,tb.name,tn.name,th.name,tv.name,tvirt.name) target_name
            FROM relations r JOIN entities se ON se.id=r.source_id JOIN entities te ON te.id=r.target_id
            LEFT JOIN address_spaces sa ON sa.entity_id=r.source_id LEFT JOIN sites ss ON ss.id=r.source_id LEFT JOIN network_nodes snn ON snn.id=r.source_id LEFT JOIN address_blocks sb ON sb.id=r.source_id LEFT JOIN subnets sn ON sn.id=r.source_id LEFT JOIN hosts sh ON sh.id=r.source_id LEFT JOIN services sv ON sv.id=r.source_id LEFT JOIN virtual_networks svirt ON svirt.id=r.source_id
            LEFT JOIN address_spaces ta ON ta.entity_id=r.target_id LEFT JOIN sites ts ON ts.id=r.target_id LEFT JOIN network_nodes tnn ON tnn.id=r.target_id LEFT JOIN address_blocks tb ON tb.id=r.target_id LEFT JOIN subnets tn ON tn.id=r.target_id LEFT JOIN hosts th ON th.id=r.target_id LEFT JOIN services tv ON tv.id=r.target_id LEFT JOIN virtual_networks tvirt ON tvirt.id=r.target_id ORDER BY r.id DESC");
    }

    public function saveRelation(array $data, ?int $id=null): array
    {
        $source=(int)($data['source_id']??0);$target=(int)($data['target_id']??0);$type=self::text($data,'type','connected');
        if($source===$target||!$this->one('SELECT id FROM entities WHERE id=?',[$source])||!$this->one('SELECT id FROM entities WHERE id=?',[$target]))throw new InvalidArgumentException('Quell- oder Zielobjekt ist ungültig.');
        $this->enum($type,['connected','routes_to','uses','depends_on','manages'],'Beziehungstyp');$values=[$source,$target,$type,self::text($data,'label'),self::text($data,'notes')];
        if($id)$this->db->prepare('UPDATE relations SET source_id=?,target_id=?,type=?,label=?,notes=? WHERE id=?')->execute([...$values,$id]);
        else{$this->db->prepare('INSERT INTO relations(source_id,target_id,type,label,notes) VALUES (?,?,?,?,?)')->execute($values);$id=(int)$this->db->lastInsertId();}
        return $this->one('SELECT * FROM relations WHERE id=?',[$id])??throw new DomainException('Beziehung nicht gefunden.');
    }

    public function topology(?int $siteId=null): array
    {
        $nodes=[];
        $spaces=$siteId?$this->all('SELECT DISTINCT a.* FROM address_spaces a JOIN sites s ON s.address_space_id=a.id WHERE s.id=?',[$siteId]):$this->all('SELECT * FROM address_spaces ORDER BY start_int');
        foreach($spaces as$row)$nodes[]=['id'=>(int)$row['entity_id'],'object_id'=>(int)$row['id'],'kind'=>'address_space','name'=>$row['name'],'subtitle'=>'ab '.$row['start_ip']];
        foreach($this->sites() as $row)if(!$siteId||(int)$row['id']===$siteId){$node=$this->node($row,'site');$node['site_id']=(int)$row['id'];foreach(['domain_name','location','allocation_mode','site_cidr','site_cidrs','site_range','allocated_start','allocated_end','usable_addresses','occupied_addresses','free_addresses','utilization_percent','area_count','subnet_count','host_count','service_count']as$key)$node[$key]=$row[$key]??'';$nodes[]=$node;}
        $networkRows=$this->all('SELECT n.*,e.kind entity_kind FROM network_nodes n JOIN entities e ON e.id=n.id '.($siteId?'WHERE n.site_id=? ':'').'ORDER BY n.network_int',$siteId?[$siteId]:[]);
        foreach($networkRows as $row){$allocation=$row['allocation_type']??'subnet';$role=$allocation==='static_pool'?'static_pool':($allocation==='dhcp_pool'?'dhcp_pool':(!empty($row['l3_enabled'])?'l3_subnet':($row['node_type']==='leaf'?'subnet':($row['entity_kind']==='subnet'?'aggregate':'group'))));$node=$this->node($row,$role==='group'?'block':'subnet');$node['node_role']=$role;$node['site_id']=(int)$row['site_id'];$node['parent_id']=$row['parent_id']===null?null:(int)$row['parent_id'];$node['vlan_id']=$row['vlan_id']===null?null:(int)$row['vlan_id'];if($node['vlan_id']!==null)$node['subtitle'].=' · VLAN '.$node['vlan_id'];$nodes[]=$node;}
        foreach($this->virtualNetworks()as$row){$node=$this->node($row,'virtual_network');$node['virtual_role']=$row['network_type'];$node['site_id']=null;$nodes[]=$node;}
        foreach($this->hosts($siteId?['site_id'=>$siteId]:[]) as $row){$node=$this->node($row,(int)($row['is_router']??0)?'router':'host');$node['site_id']=(int)$row['site_id'];$nodes[]=$node;}
        $hostIds=array_map(fn($n)=>$n['id'],array_filter($nodes,fn($n)=>in_array($n['kind'],['host','router'],true)));
        foreach($this->services() as $row)if(!$siteId||in_array($row['host_id'],$hostIds)){$node=$this->node($row,'service');$node['site_id']=(int)$row['site_id'];$nodes[]=$node;}
        $nodeIds=array_column($nodes,'id');$edges=[];
        foreach($this->sites() as$s)if((!$siteId||(int)$s['id']===$siteId)&&$s['address_space_id']){$space=$this->one('SELECT entity_id FROM address_spaces WHERE id=?',[$s['address_space_id']]);if($space&&in_array((int)$space['entity_id'],$nodeIds))$edges[]=['source'=>(int)$space['entity_id'],'target'=>(int)$s['id'],'type'=>'contains','label'=>'enthält'];}
        foreach($networkRows as $n)if(in_array($n['id'],$nodeIds))$edges[]=['source'=>(int)($n['parent_id']?:$n['site_id']),'target'=>(int)$n['id'],'type'=>'contains','label'=>'enthält'];
        foreach($this->interfaces() as $i){$source=!empty($i['pool_id'])&&in_array((int)$i['pool_id'],$nodeIds,true)?(int)$i['pool_id']:(int)$i['subnet_id'];if(in_array((int)$i['host_id'],$nodeIds,true)&&in_array($source,$nodeIds,true))$edges[]=['source'=>$source,'target'=>(int)$i['host_id'],'type'=>'assigned','label'=>$i['name'].' · '.implode(', ',$i['ips'])];}
        foreach($this->vpnInterfaces()as$i)if(in_array((int)$i['virtual_network_id'],$nodeIds,true)&&in_array((int)$i['router_id'],$nodeIds,true))$edges[]=['source'=>(int)$i['virtual_network_id'],'target'=>(int)$i['router_id'],'type'=>'assigned','label'=>$i['name'].' · '.implode(', ',$i['ips'])];
        foreach($this->virtualNetworks()as$v)if($v['network_type']==='docker_bridge'&&in_array((int)$v['id'],$nodeIds,true)&&in_array((int)$v['owner_host_id'],$nodeIds,true))$edges[]=['source'=>(int)$v['id'],'target'=>(int)$v['owner_host_id'],'type'=>'uses','label'=>'Docker-Bridge'];
        foreach($this->services() as $s)if(in_array($s['id'],$nodeIds))$edges[]=['source'=>(int)$s['host_id'],'target'=>(int)$s['id'],'type'=>'hosts','label'=>'hostet'];
        foreach($this->relations() as $r)if(in_array($r['source_id'],$nodeIds)&&in_array($r['target_id'],$nodeIds))$edges[]=['id'=>(int)$r['id'],'source'=>(int)$r['source_id'],'target'=>(int)$r['target_id'],'type'=>$r['type'],'label'=>$r['label']?:$r['type']];
        $scope=$siteId?'site-'.$siteId:'global';$positions=$this->all('SELECT * FROM topology_positions WHERE scope=?',[$scope]);
        return ['nodes'=>$nodes,'edges'=>$edges,'positions'=>$positions,'scope'=>$scope];
    }

    public function savePositions(array $data): void
    {
        $scope=self::text($data,'scope','global');$stmt=$this->db->prepare('INSERT INTO topology_positions(entity_id,scope,x,y) VALUES (?,?,?,?) ON CONFLICT(entity_id,scope) DO UPDATE SET x=excluded.x,y=excluded.y');
        $this->transaction(function()use($data,$scope,$stmt):void{foreach(($data['positions']??[])as $p)$stmt->execute([(int)$p['entity_id'],$scope,(float)$p['x'],(float)$p['y']]);});
    }

    public function deletePositions(string $scope): void
    {
        $this->db->prepare('DELETE FROM topology_positions WHERE scope=?')->execute([$scope]);
    }

    public function dashboard(): array
    {
        $count=fn(string $table)=>(int)$this->one("SELECT COUNT(*) value FROM $table")['value'];
        $subnets=$this->all("SELECT n.cidr,((SELECT COUNT(*) FROM network_interfaces i WHERE i.network_id=n.id)+(SELECT COUNT(*) FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id WHERE i.network_id=n.id)) assigned_count,(SELECT COUNT(*) FROM network_reservations r WHERE r.network_id=n.id) reserved_count FROM network_nodes n WHERE (n.node_type='leaf' AND n.allocation_type='subnet') OR n.l3_enabled=1");$usable=0;foreach($subnets as$row)$usable+=max(0,IpMath::parseCidr($row['cidr'])['usable']-1);$assigned=array_sum(array_column($subnets,'assigned_count'));$reserved=array_sum(array_column($subnets,'reserved_count'));
        return ['sites'=>$count('sites'),'blocks'=>(int)$this->one("SELECT COUNT(*) value FROM network_nodes WHERE node_type='container' AND l3_enabled=0")['value'],'subnets'=>count($subnets),'pools'=>(int)$this->one("SELECT COUNT(*) value FROM network_nodes WHERE allocation_type IN ('static_pool','dhcp_pool')")['value'],'virtual_networks'=>$count('virtual_networks'),'hosts'=>$count('hosts'),'services'=>$count('services'),'usable'=>$usable,'assigned'=>$assigned,'reserved'=>$reserved,'free'=>max(0,$usable-$assigned-$reserved),'utilization'=>$usable?round(100*($assigned+$reserved)/$usable,2):0];
    }

    public function objectOptions(): array
    {
        return $this->all("SELECT e.id,e.kind,COALESCE(a.name,s.name,nn.name,b.name,n.name,h.name,v.name,vn.name) name,
            COALESCE(s.id,nn.site_id,b.site_id,sb.site_id,h.site_id,sh.site_id,oh.site_id) site_id,
            COALESCE(s.name,nns.name,bs.name,sbs.name,hs.name,shs.name,ohs.name) site_name
            FROM entities e
            LEFT JOIN address_spaces a ON a.entity_id=e.id
            LEFT JOIN sites s ON s.id=e.id
            LEFT JOIN network_nodes nn ON nn.id=e.id LEFT JOIN sites nns ON nns.id=nn.site_id
            LEFT JOIN address_blocks b ON b.id=e.id LEFT JOIN sites bs ON bs.id=b.site_id
            LEFT JOIN subnets n ON n.id=e.id LEFT JOIN address_blocks sb ON sb.id=n.block_id LEFT JOIN sites sbs ON sbs.id=sb.site_id
            LEFT JOIN hosts h ON h.id=e.id LEFT JOIN sites hs ON hs.id=h.site_id
            LEFT JOIN services v ON v.id=e.id LEFT JOIN hosts sh ON sh.id=v.host_id LEFT JOIN sites shs ON shs.id=sh.site_id
            LEFT JOIN virtual_networks vn ON vn.id=e.id LEFT JOIN hosts oh ON oh.id=vn.owner_host_id LEFT JOIN sites ohs ON ohs.id=oh.site_id
            ORDER BY e.kind,site_name,name");
    }

    /** @return list<array{input_name:string,is_fqdn:int,fqdn:string}> */
    private function hostAliases(int $hostId): array
    {
        return $this->all("SELECT input_name,is_fqdn,fqdn FROM dns_names WHERE host_id=? AND kind='host_alias' ORDER BY fqdn", [$hostId]);
    }

    /** @return list<string> */
    private function aliasesFromData(array $data, ?int $hostId): array
    {
        if (!array_key_exists('aliases', $data)) {
            return $hostId ? array_column($this->hostAliases($hostId), 'input_name') : [];
        }
        $value = $data['aliases'];
        $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string)$value);
        $result = [];
        foreach ($items ?: [] as $item) {
            $item = trim((string)$item);
            if ($item !== '') $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    /** @param list<string> $aliases */
    private function syncHostDns(int $hostId, string $hostname, int $siteId, array $aliases): void
    {
        $site = $this->one('SELECT domain_name FROM sites WHERE id=?', [$siteId]);
        if (!$site || $site['domain_name'] === '') throw new InvalidArgumentException('Die Site besitzt keine gültige Standard-Domain.');
        $domain = DnsName::domain($site['domain_name']);
        $primary = DnsName::primary($hostname, $domain);
        $this->db->prepare("DELETE FROM dns_names WHERE host_id=? AND kind IN ('primary','host_alias')")->execute([$hostId]);
        $this->insertDns($hostId, null, 'primary', $primary['input_name'], $primary['is_fqdn'], $primary['fqdn']);
        $seen = [$primary['fqdn'] => true];
        foreach ($aliases as $value) {
            $alias = DnsName::alias($value, $domain);
            if (isset($seen[$alias['fqdn']])) throw new DomainException('DNS-Name ' . $alias['fqdn'] . ' ist innerhalb des Hosts doppelt vorhanden.');
            $seen[$alias['fqdn']] = true;
            $this->insertDns($hostId, null, 'host_alias', $alias['input_name'], $alias['is_fqdn'], $alias['fqdn']);
        }
    }

    private function rebuildSiteDns(int $siteId, string $domain): void
    {
        foreach ($this->all('SELECT id,hostname FROM hosts WHERE site_id=?', [$siteId]) as $host) {
            if ($host['hostname'] === '') continue;
            $aliases = array_column($this->hostAliases((int)$host['id']), 'input_name');
            $this->syncHostDns((int)$host['id'], $host['hostname'], $siteId, $aliases);
        }
    }

    private function insertDns(int $hostId, ?int $interfaceId, string $kind, string $input, int $isFqdn, string $fqdn): void
    {
        try {
            $this->db->prepare('INSERT INTO dns_names(host_id,network_interface_id,kind,input_name,is_fqdn,fqdn) VALUES (?,?,?,?,?,?)')->execute([$hostId,$interfaceId,$kind,$input,$isFqdn,$fqdn]);
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) throw new DomainException('DNS-Name ' . $fqdn . ' ist bereits vergeben.');
            throw $error;
        }
    }

    public function delete(string $resource,int $id): void
    {
        $tables=['sites'=>['sites',true],'blocks'=>['address_blocks',true],'subnets'=>['subnets',true],'hosts'=>['hosts',true],'routers'=>['hosts',true],'services'=>['services',true],'interfaces'=>['network_interfaces',false],'vpn-interfaces'=>['vpn_interfaces',false],'reservations'=>['network_reservations',false],'relations'=>['relations',false],'static-routes'=>['static_routes',false]];
        if(!isset($tables[$resource]))throw new InvalidArgumentException('Ressource kann nicht gelöscht werden.');
        [$table,$entity]=$tables[$resource];
        if(in_array($resource,['hosts','routers'],true)&&($this->one('SELECT id FROM virtual_networks WHERE owner_host_id=? LIMIT 1',[$id])||$this->one('SELECT id FROM vpn_interfaces WHERE router_id=? LIMIT 1',[$id])))throw new DomainException('Gerät wird von einem Docker- oder VPN-Netz verwendet. Virtuelle Netze beziehungsweise VPN-Interfaces zuerst entfernen.');
        if($resource==='hosts'){
            $this->transaction(function()use($id):void{foreach($this->all('SELECT id FROM services WHERE host_id=?',[$id])as $service)$this->db->prepare('DELETE FROM entities WHERE id=?')->execute([$service['id']]);$this->db->prepare('DELETE FROM entities WHERE id=?')->execute([$id]);});return;
        }
        if($resource==='interfaces'&&$this->one('SELECT network_id FROM gateway_bindings WHERE interface_id=? UNION ALL SELECT id FROM static_routes WHERE egress_interface_id=? LIMIT 1',[$id,$id]))throw new DomainException('Interface wird als Gateway oder von einer Route verwendet. Bindung beziehungsweise Route zuerst entfernen.');
        $stmt=$this->db->prepare('DELETE FROM '.($entity?'entities':$table).' WHERE id=?');$stmt->execute([$id]);if($stmt->rowCount()===0)throw new DomainException('Objekt nicht gefunden.');
    }

    private function networkOutput(array $row): array
    {
        $parsed=IpMath::parseCidr($row['cidr']);$row['network']=IpMath::toIp($parsed['network']);$row['broadcast']=IpMath::toIp($parsed['broadcast']);$row['first_usable']=IpMath::toIp($parsed['first_usable']);$row['last_usable']=IpMath::toIp($parsed['last_usable']);$row['size']=$parsed['size'];$row['usable']=$parsed['usable'];
        if(array_key_exists('gateway_int',$row))$row['gateway']=$row['gateway_int']===null?'':IpMath::toIp((int)$row['gateway_int']);return $row;
    }

    private function node(array $row,string $kind): array{return ['id'=>(int)$row['id'],'kind'=>$kind,'name'=>$row['name'],'subtitle'=>$row['cidr']??($kind==='host'?($row['fqdn']??$row['hostname']):($row['status']??''))];}
    /** @return list<string> */
    private function ipInputs(array$data):array
    {
        $value=$data['ips']??($data['ip']??'');$items=is_array($value)?$value:preg_split('/[\s,;]+/',trim((string)$value));$result=[];foreach($items?:[]as$item){$item=trim((string)$item);if($item!=='')$result[]=$item;}if(count($result)!==count(array_unique($result)))throw new DomainException('Eine IP-Adresse wurde innerhalb des Interfaces mehrfach angegeben.');return$result;
    }
    /** @return array{0:?int,1:?array<string,mixed>} */
    private function nextFreeInterfaceAddress(array$subnet,?array$selectedPool,?int$excludeInterfaceId):array
    {
        $used=[];
        foreach($this->all('SELECT ip_int FROM network_interfaces WHERE id<>? UNION SELECT a.ip_int FROM network_interface_addresses a WHERE a.interface_id<>? UNION SELECT ip_int FROM network_reservations UNION SELECT ip_int FROM vpn_interfaces UNION SELECT ip_int FROM vpn_interface_addresses',[$excludeInterfaceId??0,$excludeInterfaceId??0])as$row)$used[(int)$row['ip_int']]=true;
        $gateway=$subnet['gateway_int']===null?null:(int)$subnet['gateway_int'];
        $network=IpMath::parseCidr($subnet['cidr']);
        $find=function(int$start,int$end,array$excluded=[])use($used,$gateway):?int{
            for($ip=$start;$ip<=$end;){
                if(isset($used[$ip])||$gateway===$ip){$ip++;continue;}
                $skipped=false;
                foreach($excluded as[$rangeStart,$rangeEnd])if($ip>=$rangeStart&&$ip<=$rangeEnd){$ip=$rangeEnd+1;$skipped=true;break;}
                if(!$skipped)return$ip;
            }
            return null;
        };
        $poolRange=static function(array$pool):array{return[(int)$pool['network_int']+(int)($pool['pool_start_offset']??0),(int)$pool['network_int']+(int)($pool['pool_end_offset']??((int)$pool['broadcast_int']-(int)$pool['network_int']))];};
        $preparePool=static function(array$pool,array$range):array{$pool['range_start']=$range[0];$pool['range_end']=$range[1];return$pool;};
        if($selectedPool){$range=$poolRange($selectedPool);$ip=$find(max($network['first_usable'],$range[0]),min($network['last_usable'],$range[1]));return[$ip,$ip===null?null:$preparePool($selectedPool,$range)];}
        $pools=[];
        foreach($this->all("SELECT * FROM network_nodes WHERE site_id=? AND allocation_type IN ('static_pool','dhcp_pool') ORDER BY CASE allocation_type WHEN 'static_pool' THEN 0 ELSE 1 END,network_int",[$subnet['site_id']])as$pool)if($this->l3NetworkId($pool)===(int)$subnet['id']){$range=$poolRange($pool);$pools[]=$preparePool($pool,$range);}
        foreach($pools as$pool)if($pool['allocation_type']==='static_pool'){$ip=$find(max($network['first_usable'],(int)$pool['range_start']),min($network['last_usable'],(int)$pool['range_end']));if($ip!==null)return[$ip,$pool];}
        $excluded=array_map(fn($pool)=>[(int)$pool['range_start'],(int)$pool['range_end']],$pools);
        return[$find($network['first_usable'],$network['last_usable'],$excluded),null];
    }
    private function assertAddressAvailable(int$ip,?int$normalInterfaceId,?int$vpnInterfaceId):void
    {
        if($this->one('SELECT id FROM network_interfaces WHERE ip_int=? AND id<>? LIMIT 1',[$ip,$normalInterfaceId??0])||$this->one('SELECT a.id FROM network_interface_addresses a WHERE a.ip_int=? AND a.interface_id<>? LIMIT 1',[$ip,$normalInterfaceId??0])||$this->one('SELECT id FROM network_reservations WHERE ip_int=? LIMIT 1',[$ip]))throw new DomainException('IP-Adresse '.IpMath::toIp($ip).' ist bereits in der normalen Adressplanung belegt.');
        if($this->one('SELECT id FROM vpn_interfaces WHERE ip_int=? AND id<>? LIMIT 1',[$ip,$vpnInterfaceId??0])||$this->one('SELECT a.id FROM vpn_interface_addresses a WHERE a.ip_int=? AND a.interface_id<>? LIMIT 1',[$ip,$vpnInterfaceId??0]))throw new DomainException('IP-Adresse '.IpMath::toIp($ip).' ist bereits auf einem VPN-Interface vergeben.');
    }
    private function touch(int $id):void{$this->db->prepare('UPDATE entities SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);}
    private function enum(string $value,array $allowed,string $label):void{if(!in_array($value,$allowed,true))throw new InvalidArgumentException("$label ist ungültig.");}
}
