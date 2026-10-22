<?php
declare(strict_types=1);

namespace IpDesigner;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;

/** Bulk host/LAN/VPN operations reuse the same validation as individual edits. */
final class HostCsvTransfer
{
    public const COLUMNS = ['host_id','site','name','hostname','type','status','description','is_router',
        'interface_id','interface_type','interface_name','network_id','network','pool_id','pool',
        'ips','mac_address','dns_name','is_gateway','confirm_dhcp_overlap'];
    private const HOST_FIELDS = ['site_id','name','hostname','type','status','description','is_router'];

    public function __construct(private readonly PDO $db) {}

    public function template(): array
    {
        return ['filename'=>'hosts-vorlage.csv','csv'=>Csv::encode(self::COLUMNS, [])];
    }

    public function export(array $filters = []): array
    {
        // A snapshot keeps all exported host and interface fields consistent.
        [$copy] = $this->snapshot(true);
        $repo = new Repository($copy);
        $nodes = array_column($copy->query('SELECT * FROM network_nodes')->fetchAll(), null, 'id');
        $selected = null;
        if (isset($filters['host_ids'])) {
            if (!is_string($filters['host_ids']) || !preg_match('/^\d+(?:,\d+)*$/D', $filters['host_ids'])) throw new InvalidArgumentException('Ungültige Hostauswahl.');
            $selected = array_fill_keys(explode(',', $filters['host_ids']), true);
        }
        $rows = []; $count = 0;
        foreach ($repo->hosts() as $host) {
            if ($selected !== null && !isset($selected[$host['id']])) continue;
            $count++;
            $base = ['host_id'=>$host['id'],'site'=>$host['site_name'],'name'=>$host['name'],
                'hostname'=>$host['hostname'],'type'=>$host['type'],'status'=>$host['status'],
                'description'=>$host['description'],'is_router'=>$host['is_router']];
            $interfaces = $host['interfaces'];
            foreach ($interfaces as $interface) {
                $rows[] = $base + ['interface_id'=>$interface['id'],'interface_type'=>'lan','interface_name'=>$interface['name'],
                    'network_id'=>$interface['network_id'],'network'=>$nodes[$interface['network_id']]['name'],
                    'pool_id'=>$interface['pool_id'],'pool'=>$interface['pool_name'],'ips'=>implode(' | ', $interface['ips']),
                    'mac_address'=>$interface['mac_address'],'dns_name'=>$interface['dns_name'],'is_gateway'=>(int)$interface['is_gateway'],
                    'confirm_dhcp_overlap'=>$interface['pool_type'] === 'dhcp_pool' ? 1 : 0];
            }
            $vpn = $repo->vpnInterfaces(null, (int)$host['id']);
            foreach ($vpn as $interface) {
                $rows[] = $base + ['interface_id'=>$interface['id'],'interface_type'=>'vpn','interface_name'=>$interface['name'],
                    'network_id'=>$interface['virtual_network_id'],'network'=>$interface['network_name'],'ips'=>implode(' | ', $interface['ips'])];
            }
            if (!$interfaces && !$vpn) $rows[] = $base;
        }
        return ['filename'=>'hosts.csv','csv'=>Csv::encode(self::COLUMNS, $rows),'hosts'=>$count,'rows'=>count($rows)];
    }

    public function preview(array $input): array
    {
        [$csv, $allowUpdates, $records] = $this->input($input);
        [$copy, $revision] = $this->snapshot(true);
        $copy->beginTransaction();
        try { return $this->simulate($copy, $records, $allowUpdates, $revision, $csv); }
        finally { $copy->rollBack(); }
    }

    public function apply(array $input): array
    {
        [$csv, $allowUpdates, $records] = $this->input($input);
        foreach (['revision','plan_hash'] as $key) if (!is_string($input[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input[$key])) throw new InvalidArgumentException('Zuerst eine gültige CSV-Vorschau erstellen.');
        if ($this->db->inTransaction()) throw new DomainException('CSV-Import benötigt eine eigene Transaktion.');
        $this->db->beginTransaction();
        try {
            [, $revision] = $this->snapshot(false);
            if (!hash_equals($revision, $input['revision'])) throw new DomainException('Planungsdaten wurden seit der Vorschau geändert. CSV erneut prüfen.');
            $result = $this->simulate($this->db, $records, $allowUpdates, $revision, $csv);
            if (!$result['can_apply']) throw new DomainException('CSV enthält Fehler oder erzeugt Netzcheck-Fehler. Nichts gespeichert; Vorschau prüfen.');
            if (!hash_equals($result['plan_hash'], $input['plan_hash'])) throw new DomainException('CSV oder Importoptionen wurden seit der Vorschau geändert. CSV erneut prüfen.');
            $this->db->commit();
            return ['saved'=>true,'summary'=>$result['summary'],'rows'=>$result['rows'],'network_check'=>$result['network_check']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function input(array $input): array
    {
        if (!is_string($input['csv'] ?? null)) throw new InvalidArgumentException('CSV-Text fehlt.');
        $updates = $input['allow_updates'] ?? false;
        if (!is_bool($updates)) throw new InvalidArgumentException('Update-Option muss ein Wahrheitswert sein.');
        return [$input['csv'], $updates, Csv::decode($input['csv'], self::COLUMNS, ['site','name','hostname'])];
    }

    /** Only SELECTs on the source, including on a read-only SQLite connection. */
    private function snapshot(bool $clone): array
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $schema = $this->db->query("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY type,name")->fetchAll();
            $data = [];
            foreach ($schema as $item) if ($item['type'] === 'table') $data[$item['name']] = $this->db->query('SELECT * FROM '.self::identifier($item['name']).' ORDER BY rowid')->fetchAll();
            $sequences = $this->db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll();
            $revision = hash('sha256', json_encode([$schema,$data,$sequences], JSON_THROW_ON_ERROR));
            if ($owns) $this->db->commit();
        } catch (\Throwable $error) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        if (!$clone) return [null, $revision];
        $copy = Database::connect(':memory:');
        $copy->exec('PRAGMA foreign_keys=OFF');
        $copy->beginTransaction();
        foreach ($schema as $item) if ($item['type'] === 'table') $copy->exec($item['sql']);
        foreach ($data as $table=>$rows) {
            if (!$rows) continue;
            $columns = array_map([self::class,'identifier'], array_keys($rows[0]));
            $insert = $copy->prepare('INSERT INTO '.self::identifier($table).' ('.implode(',', $columns).') VALUES ('.implode(',', array_fill(0,count($columns),'?')).')');
            foreach ($rows as $row) $insert->execute(array_values($row));
        }
        $copy->exec('DELETE FROM sqlite_sequence');
        $insert = $copy->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES (?,?)');
        foreach ($sequences as $row) $insert->execute(array_values($row));
        foreach ($schema as $item) if ($item['type'] !== 'table') $copy->exec($item['sql']);
        $copy->commit(); $copy->exec('PRAGMA foreign_keys=ON');
        return [$copy, $revision];
    }

    private static function identifier(string $name): string { return '"'.str_replace('"', '""', $name).'"'; }

    private function simulate(PDO $db, array $records, bool $updates, string $revision, string $csv): array
    {
        $repo = new Repository($db);
        $hosts = array_column($repo->hosts(), null, 'id');
        $sites = $db->query('SELECT * FROM sites')->fetchAll();
        $nodes = $db->query('SELECT * FROM network_nodes')->fetchAll();
        $virtuals = $db->query("SELECT * FROM virtual_networks WHERE network_type='vpn'")->fetchAll();
        $lan = array_column($repo->interfaces(), null, 'id');
        $vpn = array_column($repo->vpnInterfaces(), null, 'id');
        $groups = $seenInterfaces = $rows = $errors = [];
        $summary = ['hosts_created'=>0,'hosts_updated'=>0,'interfaces_created'=>0,'interfaces_updated'=>0];
        foreach ($records as $record) {
            $row = $record['values']; $db->exec('SAVEPOINT csv_row');
            try {
                $hostId = self::id($row['host_id'] ?? '');
                $interfaceId = self::id($row['interface_id'] ?? '');
                if (($hostId || $interfaceId) && !$updates) throw new DomainException('Bestehende IDs nur mit aktivierter Option „Bestehende IDs aktualisieren“ importieren.');
                $original = $hostId ? ($hosts[$hostId] ?? throw new DomainException('Host-ID wurde nicht gefunden.')) : null;
                $site = self::resolve($sites, '', $row['site'], 'Site');
                $hostData = $original ?? ['type'=>'server','status'=>'planned','description'=>'','notes'=>'','is_router'=>0];
                foreach (['name','hostname','type','status','description'] as $field) if (array_key_exists($field, $row)) $hostData[$field] = $row[$field];
                $hostData['site_id'] = (int)$site['id'];
                if (isset($row['is_router'])) $hostData['is_router'] = self::flag($row['is_router']);
                if ($hostData['type'] === '') $hostData['type'] = !empty($hostData['is_router']) ? 'network' : 'server';
                if ($hostData['status'] === '') $hostData['status'] = 'planned';
                // Alias lists in Repository output are objects; absence preserves stored aliases.
                unset($hostData['aliases']);
                if ($original && (int)$original['site_id'] !== (int)$site['id']) throw new DomainException('Site-Wechsel bestehender Hosts bitte im Einzeldialog durchführen.');
                if ($original && (int)$original['is_router'] !== (int)$hostData['is_router']) throw new DomainException('Routerrolle bestehender Hosts kann nicht per CSV geändert werden.');
                $key = $hostId ? 'id:'.$hostId : 'new:'.$site['id'].':'.mb_strtolower($row['name']);
                $signature = self::hostValues($hostData);
                $group = $groups[$key] ?? null;
                if ($group && $group['signature'] !== $signature) throw new DomainException('Widersprüchliche Hostfelder in mehreren CSV-Zeilen desselben Hosts.');
                $hostAction = 'unchanged';
                if ($group) $host = $group['host'];
                elseif (!$original || self::hostValues($original) !== $signature) {
                    $host = $repo->saveHost($hostData, $hostId);
                    $hostAction = $original ? 'updated' : 'created';
                } else $host = $original;
                $interface = $beforeInterface = null; $interfaceAction = 'none'; $interfaceKey = null;
                $hasInterface = ($row['interface_name'] ?? '') !== '' || $interfaceId !== null;
                if (!$hasInterface && array_filter(array_intersect_key($row, array_flip(['interface_type','network_id','network','pool_id','pool','ips','mac_address','dns_name','is_gateway','confirm_dhcp_overlap'])))) throw new DomainException('Interface-Name fehlt zu den angegebenen Netzwerkdaten.');
                if ($hasInterface) {
                    $kind = ($row['interface_type'] ?? '') ?: 'lan';
                    if (!in_array($kind, ['lan','vpn'], true)) throw new InvalidArgumentException('interface_type muss lan oder vpn sein.');
                    $beforeInterface = $interfaceId ? (($kind === 'lan' ? $lan : $vpn)[$interfaceId] ?? throw new DomainException('Interface-ID wurde für diesen Typ nicht gefunden.')) : null;
                    if ($beforeInterface && (int)($beforeInterface['host_id'] ?? $beforeInterface['router_id']) !== (int)$host['id']) throw new DomainException('Interface-ID gehört zu einem anderen Host.');
                    $candidates = $kind === 'vpn' ? $virtuals : array_values(array_filter($nodes, static fn($n) => NetworkCapacity::isL3($n) && ((int)$n['site_id'] === (int)$site['id'] || (int)$n['id'] === (int)($beforeInterface['network_id'] ?? 0))));
                    $network = self::resolve($candidates, $row['network_id'] ?? '', $row['network'] ?? '', 'Netz', $beforeInterface[$kind === 'vpn' ? 'virtual_network_id' : 'network_id'] ?? null);
                    $data = $beforeInterface ?? [];
                    $data['name'] = $row['interface_name'] ?? ($data['name'] ?? '');
                    if (array_key_exists('ips', $row)) $data['ips'] = preg_split('/[\s,;|]+/', trim($row['ips']), -1, PREG_SPLIT_NO_EMPTY);
                    $interfaceKey = $kind.':'.($interfaceId ? 'id:'.$interfaceId : 'new:'.$host['id'].':'.$data['name'].':'.($kind === 'vpn' ? $network['id'] : ''));
                    if (isset($seenInterfaces[$interfaceKey])) throw new DomainException('Dasselbe Interface wird in mehreren CSV-Zeilen angegeben.');
                    if ($kind === 'vpn') {
                        foreach (['pool_id','pool','mac_address','dns_name'] as $field) if (($row[$field] ?? '') !== '') throw new InvalidArgumentException($field.' ist bei VPN-Interfaces nicht unterstützt.');
                        if (self::flag($row['is_gateway'] ?? '')) throw new InvalidArgumentException('VPN-Interfaces sind keine LAN-Gateway-Bindungen.');
                        $data['router_id'] = $host['id']; $data['virtual_network_id'] = $network['id'];
                        $interface = $repo->saveVpnInterface($data, $interfaceId);
                    } else {
                        $data['host_id'] = $host['id']; $data['network_id'] = $network['id'];
                        if (array_key_exists('pool_id', $row) || array_key_exists('pool', $row)) {
                            $data['pool_id'] = null;
                            if (($row['pool_id'] ?? '') !== '' || ($row['pool'] ?? '') !== '') {
                                $pool = self::resolve(array_values(array_filter($nodes, static fn($n) => NetworkCapacity::isPool($n) && (int)$n['site_id'] === (int)$site['id'])), $row['pool_id'] ?? '', $row['pool'] ?? '', 'Pool');
                                $data['pool_id'] = $pool['id'];
                            }
                        }
                        foreach (['mac_address','dns_name'] as $field) if (array_key_exists($field, $row)) $data[$field] = $row[$field];
                        $data['is_gateway'] = array_key_exists('is_gateway', $row) ? self::flag($row['is_gateway']) : ($data['is_gateway'] ?? 0);
                        $data['confirm_dhcp_overlap'] = self::flag($row['confirm_dhcp_overlap'] ?? '');
                        if (!empty($beforeInterface['is_gateway']) && (!$data['is_gateway'] || (int)$beforeInterface['network_id'] !== (int)$network['id'])) throw new DomainException('Bestehende Gateway-Bindungen bitte im Routerdialog ändern.');
                        // Empty router IPs also request a gateway in Repository::saveInterface().
                        $requestsGateway = !empty($data['is_gateway']) || (!empty($host['is_router']) && empty($data['ips']));
                        if ($requestsGateway) {
                            $binding = $db->prepare('SELECT interface_id FROM gateway_bindings WHERE network_id=?');
                            $binding->execute([$network['id']]);
                            $boundInterfaceId = $binding->fetchColumn();
                            if ($boundInterfaceId !== false && (int)$boundInterfaceId !== $interfaceId) throw new DomainException('Das Netz besitzt bereits ein anderes Gateway-Interface. Gateway-Zuordnung bitte im Routerdialog ändern.');
                        }
                        $interface = $repo->saveInterface($data, $interfaceId);
                    }
                    $interface['interface_type'] = $kind;
                    if ($beforeInterface) $beforeInterface['interface_type'] = $kind;
                    $interfaceAction = !$beforeInterface ? 'created' : (self::interfaceValues($beforeInterface) === self::interfaceValues($interface) ? 'unchanged' : 'updated');
                    if ($interfaceAction === 'unchanged' && $hostAction === 'unchanged') {
                        // A round-trip of unchanged CSV must not rewrite DNS/secondary IDs.
                        $db->exec('ROLLBACK TO csv_row');
                    }
                }
                $db->exec('RELEASE csv_row');
                if (!$group) {
                    $groups[$key] = ['signature'=>$signature,'host'=>$host];
                    if ($hostAction !== 'unchanged') $summary['hosts_'.$hostAction]++;
                }
                if ($interfaceKey) $seenInterfaces[$interfaceKey] = true;
                if (in_array($interfaceAction, ['created','updated'], true)) $summary['interfaces_'.$interfaceAction]++;
                $rows[] = ['record'=>$record['record'],'site'=>$site['name'],'name'=>$host['name'],
                    'host_action'=>$hostAction,'interface_action'=>$interfaceAction,
                    'before'=>['host'=>$original ? self::hostValues($original) : null,'interface'=>$beforeInterface ? self::interfaceValues($beforeInterface) : null],
                    'after'=>['host'=>self::hostValues($host),'interface'=>$interface ? self::interfaceValues($interface) : null]];
            } catch (InvalidArgumentException|DomainException|PDOException $error) {
                $db->exec('ROLLBACK TO csv_row'); $db->exec('RELEASE csv_row');
                $message = $error instanceof PDOException ? 'Datenbankkonflikt: Name, IP oder Zuordnung bereits vergeben bzw. ungültig.' : $error->getMessage();
                $errors[] = ['record'=>$record['record'],'message'=>$message];
            }
        }
        $check = (new NetworkCheck($db))->run();
        $result = ['revision'=>$revision,'summary'=>$summary,'rows'=>$rows,'errors'=>$errors,
            'network_check'=>['summary'=>$check['summary'],'findings'=>$check['findings']],
            'can_apply'=>!$errors && $check['summary']['error'] === 0];
        $result['plan_hash'] = hash('sha256', json_encode([$csv,$updates,$result], JSON_THROW_ON_ERROR));
        return $result;
    }

    private static function hostValues(array $host): array
    {
        $values = [];
        foreach (self::HOST_FIELDS as $field) $values[$field] = in_array($field, ['site_id','is_router'], true) ? (int)$host[$field] : (string)$host[$field];
        return $values;
    }

    private static function interfaceValues(array $row): array
    {
        return ['interface_type'=>$row['interface_type'],'name'=>$row['name'],
            'network_id'=>(int)($row['network_id'] ?? $row['virtual_network_id']),
            'pool_id'=>isset($row['pool_id']) ? (int)$row['pool_id'] : null,'ips'=>$row['ips'],
            'mac_address'=>(string)($row['mac_address'] ?? ''),'dns_name'=>(string)($row['dns_name'] ?? ''),'is_gateway'=>(int)($row['is_gateway'] ?? 0)];
    }

    private static function id(string $value): ?int
    {
        if ($value === '') return null;
        if (!ctype_digit($value) || (int)$value < 1 || strlen($value) > 15) throw new InvalidArgumentException('IDs müssen positive ganze Zahlen sein.');
        return (int)$value;
    }

    private static function flag(string $value): int
    {
        return match (strtolower($value)) { '', '0', 'false', 'nein'=>0, '1', 'true', 'ja'=>1, default=>throw new InvalidArgumentException('Ja/Nein-Felder akzeptieren nur 0/1, true/false oder ja/nein.') };
    }

    private static function resolve(array $candidates, string $id, string $name, string $label, mixed $fallback = null): array
    {
        $number = self::id($id);
        if ($number === null && $name === '' && $fallback !== null) $number = (int)$fallback;
        $matches = array_values(array_filter($candidates, static fn($row) => $number !== null ? (int)$row['id'] === $number : mb_strtolower($row['name']) === mb_strtolower($name)));
        if (count($matches) !== 1) throw new DomainException($label.' fehlt, ist ungültig oder nicht eindeutig. Site und ID prüfen.');
        if ($name !== '' && mb_strtolower($matches[0]['name']) !== mb_strtolower($name)) throw new DomainException($label.': ID und Name widersprechen sich.');
        return $matches[0];
    }
}
