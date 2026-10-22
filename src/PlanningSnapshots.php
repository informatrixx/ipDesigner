<?php
declare(strict_types=1);

namespace IpDesigner;

use DomainException;
use InvalidArgumentException;
use PDO;

/** Immutable data-only snapshots. Schema, application code and snapshot history never roll back. */
final class PlanningSnapshots
{
    private const FORMAT = 1;
    private const MAX_BYTES = 33554432;
    private const TABLES = [
        'entities'=>'Objekte', 'address_spaces'=>'Adressräume', 'sites'=>'Sites',
        'address_blocks'=>'Alte Bereiche', 'subnets'=>'Alte Subnetze', 'hosts'=>'Hosts',
        'interfaces'=>'Alte Interfaces', 'dns_names'=>'DNS-Namen', 'network_nodes'=>'Netzwerke / Pools',
        'network_interfaces'=>'LAN-Interfaces', 'network_interface_addresses'=>'Weitere LAN-IPs',
        'network_reservations'=>'Reservierungen', 'gateway_bindings'=>'Gateway-Zuordnungen',
        'static_routes'=>'Statische Routen', 'virtual_networks'=>'VPN-/Docker-Netze',
        'vpn_interfaces'=>'VPN-Interfaces', 'vpn_interface_addresses'=>'Weitere VPN-IPs',
        'services'=>'Dienste', 'reservations'=>'Alte Reservierungen', 'relations'=>'Beziehungen',
        'topology_positions'=>'Topologiepositionen', 'replan_runs'=>'Replan-Historie',
    ];

    public function __construct(private readonly PDO $db) {}

    public function epoch(): string
    {
        $this->assertReady();
        return (string)$this->db->query('SELECT restore_epoch FROM planning_state WHERE id=1')->fetchColumn();
    }

    public function list(): array
    {
        $this->assertReady();
        return array_map([$this,'metadata'], $this->db->query('SELECT id,name,description,kind,source_snapshot_id,format_version,schema_version,schema_hash,payload_hash,summary,created_at FROM planning_snapshots ORDER BY id DESC')->fetchAll());
    }

    public function create(array $input): array
    {
        $name = self::text($input, 'name', 120);
        if ($name === '') throw new InvalidArgumentException('Ein Name für den Snapshot ist erforderlich.');
        $description = self::text($input, 'description', 2000);
        $this->assertReady(); $this->ownTransaction();
        $this->db->beginTransaction();
        try {
            $this->acquireWriteLock();
            $snapshot = $this->store($this->capture(), $name, $description, 'manual');
            $this->db->commit();
            return $snapshot;
        } catch (\Throwable $error) { $this->rollback(); throw $error; }
    }

    public function preview(int $id): array
    {
        $this->assertReady(); $this->ownTransaction();
        $this->db->beginTransaction();
        try {
            $result = $this->buildPreview($id);
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) { $this->rollback(); throw $error; }
    }

    public function restore(int $id, array $input): array
    {
        if (($input['confirm_restore'] ?? null) !== true) throw new InvalidArgumentException('Die vollständige Wiederherstellung muss ausdrücklich bestätigt werden.');
        foreach (['revision','plan_hash'] as $key) {
            if (!is_string($input[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input[$key])) throw new InvalidArgumentException('Zuerst eine gültige Wiederherstellungsvorschau erstellen.');
        }
        $this->assertReady(); $this->ownTransaction();
        $this->db->beginTransaction();
        try {
            $this->acquireWriteLock();
            $preview = $this->buildPreview($id);
            if (!hash_equals($preview['revision'], $input['revision']) || !hash_equals($preview['plan_hash'], $input['plan_hash'])) throw new DomainException('Planungsstand oder Snapshot wurde seit der Vorschau geändert. Vorschau erneut erstellen.');
            if ($preview['requires_error_confirmation'] && ($input['confirm_errors'] ?? null) !== true) throw new DomainException('Der Zielstand enthält Netzcheck-Fehler. Diese müssen zusätzlich ausdrücklich bestätigt werden.');
            $target = $this->load($id);
            $before = $this->capture();
            $rescue = $this->store($before, 'Vor Wiederherstellung #'.$id, 'Automatische Sicherung vor Wiederherstellung von „'.$preview['snapshot']['name'].'“.', 'pre_restore', $id);
            $this->replace($this->db, $target['data'], $before['sequences']);
            $epoch = bin2hex(random_bytes(16));
            $this->db->prepare('UPDATE planning_state SET restore_epoch=? WHERE id=1')->execute([$epoch]);
            // Live triggers may alter restored rows, including on the epoch update.
            // Never report an exact restore unless the committed data would match it.
            if ($this->epoch() !== $epoch) throw new DomainException('Die Wiederherstellungskennung wurde unerwartet verändert. Nichts wurde wiederhergestellt.');
            if ($this->capture()['tables'] !== $target['data']['tables']) throw new DomainException('Die Datenbank hat den Zielstand während der Wiederherstellung verändert. Nichts wurde wiederhergestellt.');
            if ($this->db->query('PRAGMA foreign_key_check')->fetch()) throw new DomainException('Wiederherstellung verletzt Datenbankbeziehungen. Nichts wurde wiederhergestellt.');
            $this->db->commit();
            return ['restored'=>true,'snapshot_id'=>$id,'rescue_snapshot'=>$rescue,'epoch'=>$epoch];
        } catch (\Throwable $error) { $this->rollback(); throw $error; }
    }

    private function assertReady(): void
    {
        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('planning_snapshots','planning_state','schema_migrations')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== 3 || (int)$this->db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() < 16) throw new SchemaNotReady('Planungssnapshots benötigen die explizite Migration: php bin/migrate.php. Bestehende Planungsfunktionen bleiben verfügbar.');
        $epoch = $this->db->query('SELECT restore_epoch FROM planning_state WHERE id=1')->fetchColumn();
        if (!is_string($epoch) || !preg_match('/^[a-f0-9]{32}$/D', $epoch)) throw new SchemaNotReady('Snapshot-Metadaten fehlen oder sind ungültig. Migration und Datenbank prüfen.');
    }

    private function ownTransaction(): void
    {
        if ($this->db->inTransaction()) throw new DomainException('Snapshot-Vorgänge benötigen eine eigene Transaktion.');
    }

    private function acquireWriteLock(): void
    {
        // PHP 8.2 does not track transactions opened with exec('BEGIN IMMEDIATE').
        // Start through PDO and reserve SQLite's writer before any planning reads.
        // This no-op also acquires the lock before the first snapshot row exists.
        $this->db->exec("UPDATE sqlite_sequence SET seq=seq WHERE name='planning_snapshots'");
    }

    private function rollback(): void { if ($this->db->inTransaction()) $this->db->rollBack(); }

    private static function text(array $input, string $field, int $max): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || mb_strlen($value) > $max) throw new InvalidArgumentException('Ungültiges Feld '.$field.' (maximal '.$max.' Zeichen).');
        return trim($value);
    }

    private static function encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }
    private static function quote(string $name): string { return '"'.str_replace('"', '""', $name).'"'; }

    /** Only schema from the running database is trusted; payloads contain no SQL. */
    private function schema(): array
    {
        $schema = $this->db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY type,name")->fetchAll();
        foreach ($schema as $item) {
            if ($item['type'] === 'table' && !isset(self::TABLES[$item['name']]) && !in_array($item['name'], ['schema_migrations','planning_snapshots','planning_state'], true)) throw new DomainException('Unbekannte Planungstabelle '.$item['name'].': Snapshot-Unterstützung muss vor einer Sicherung aktualisiert werden.');
        }
        return array_values(array_filter($schema, static fn($row)=>isset(self::TABLES[$row['tbl_name']])));
    }

    private function columns(string $table): array
    {
        $columns = $this->db->query('PRAGMA table_info('.self::quote($table).')')->fetchAll();
        if (!$columns) throw new DomainException('Snapshot-Tabelle fehlt: '.$table);
        return $columns;
    }

    private function capture(): array
    {
        $tables = [];
        foreach (self::TABLES as $table=>$label) {
            $keys = array_values(array_filter($this->columns($table), static fn($column)=>(int)$column['pk'] > 0));
            usort($keys, static fn($a,$b)=>(int)$a['pk'] <=>(int)$b['pk']);
            $order = implode(',', array_map(static fn($column)=>self::quote($column['name']), $keys));
            $tables[$table] = $this->db->query('SELECT * FROM '.self::quote($table).' ORDER BY '.$order)->fetchAll();
        }
        $sequences = [];
        foreach ($this->db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll() as $row) if (isset(self::TABLES[$row['name']])) $sequences[$row['name']] = (int)$row['seq'];
        return ['format_version'=>self::FORMAT,'schema_version'=>(int)$this->db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn(),
            'schema_hash'=>hash('sha256', self::encode($this->schema())), 'tables'=>$tables,'sequences'=>$sequences];
    }

    private function store(array $data, string $name, string $description, string $kind, ?int $source = null): array
    {
        $payload = self::encode($data);
        if (strlen($payload) > self::MAX_BYTES) throw new DomainException('Planungsstand überschreitet die Snapshot-Grenze von 32 MiB. Externes Datenbankbackup verwenden.');
        $counts = array_map('count', $data['tables']);
        $summary = ['tables'=>count($counts),'rows'=>array_sum($counts),'hosts'=>$counts['hosts'],'sites'=>$counts['sites'],'networks'=>$counts['network_nodes']+$counts['virtual_networks']+$counts['subnets']];
        $this->db->prepare('INSERT INTO planning_snapshots(name,description,kind,source_snapshot_id,format_version,schema_version,schema_hash,payload_hash,payload,summary) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$name,$description,$kind,$source,self::FORMAT,$data['schema_version'],$data['schema_hash'],hash('sha256',$payload),$payload,self::encode($summary)]);
        $stmt = $this->db->prepare('SELECT * FROM planning_snapshots WHERE id=?'); $stmt->execute([(int)$this->db->lastInsertId()]);
        return $this->metadata($stmt->fetch());
    }

    private function metadata(array $row): array
    {
        unset($row['payload']);
        try { $summary = json_decode($row['summary'], true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('Snapshot-Zusammenfassung ist beschädigt.'); }
        if (!is_array($summary) || array_keys($summary) !== ['tables','rows','hosts','sites','networks']) throw new DomainException('Snapshot-Zusammenfassung ist ungültig.');
        foreach ($summary as $value) if (!is_int($value) || $value < 0) throw new DomainException('Snapshot-Zusammenfassung ist ungültig.');
        $row['summary'] = $summary;
        return $row;
    }

    private function load(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM planning_snapshots WHERE id=?'); $stmt->execute([$id]); $row = $stmt->fetch();
        if (!$row) throw new DomainException('Snapshot wurde nicht gefunden.');
        if (strlen($row['payload']) > self::MAX_BYTES || !hash_equals($row['payload_hash'], hash('sha256', $row['payload']))) throw new DomainException('Snapshot-Prüfsumme stimmt nicht. Keine Wiederherstellung möglich.');
        try { $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('Snapshot-Inhalt ist beschädigt.'); }
        if (!is_array($data) || array_keys($data) !== ['format_version','schema_version','schema_hash','tables','sequences'] || $data['format_version'] !== self::FORMAT || (int)$row['format_version'] !== self::FORMAT) throw new DomainException('Snapshot-Format ist nicht unterstützt.');
        $version = (int)$this->db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        $schemaHash = hash('sha256', self::encode($this->schema()));
        if ($data['schema_version'] !== $version || (int)$row['schema_version'] !== $version || $data['schema_hash'] !== $schemaHash || $row['schema_hash'] !== $schemaHash) throw new DomainException('Snapshot passt nicht zum aktuellen Datenbankschema. Kein Schema-Downgrade möglich.');
        if (!is_array($data['tables']) || array_keys($data['tables']) !== array_keys(self::TABLES) || !is_array($data['sequences'])) throw new DomainException('Snapshot enthält nicht die erwarteten Planungstabellen.');
        foreach (self::TABLES as $table=>$label) {
            $columns = array_column($this->columns($table), 'name');
            if (!is_array($data['tables'][$table]) || !array_is_list($data['tables'][$table])) throw new DomainException('Ungültige Snapshot-Tabelle '.$table.'.');
            foreach ($data['tables'][$table] as $record) {
                if (!is_array($record) || array_keys($record) !== $columns) throw new DomainException('Snapshot-Spalten stimmen nicht überein: '.$table.'.');
                foreach ($record as $value) if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) throw new DomainException('Ungültiger Snapshot-Zellwert.');
            }
        }
        foreach ($data['sequences'] as $table=>$sequence) if (!isset(self::TABLES[$table]) || !is_int($sequence) || $sequence < 0) throw new DomainException('Ungültige Snapshot-ID-Sequenz.');
        return ['metadata'=>$this->metadata($row),'data'=>$data];
    }

    private function cloneTarget(array $target): PDO
    {
        $copy = Database::connect(':memory:');
        $copy->exec('PRAGMA foreign_keys=OFF');
        foreach ($this->schema() as $item) if ($item['type'] === 'table') $copy->exec($item['sql']);
        foreach ($this->schema() as $item) if ($item['type'] === 'index') $copy->exec($item['sql']);
        $copy->exec('PRAGMA foreign_keys=ON'); $copy->beginTransaction();
        try { $this->replace($copy, $target, []); $copy->commit(); }
        catch (\Throwable $error) { if ($copy->inTransaction()) $copy->rollBack(); throw new DomainException('Snapshot-Daten sind strukturell inkonsistent; keine Wiederherstellung möglich.', 0, $error); }
        return $copy;
    }

    private function replace(PDO $db, array $target, array $highWater): void
    {
        $db->exec('PRAGMA defer_foreign_keys=ON');
        foreach (array_reverse(array_keys(self::TABLES)) as $table) $db->exec('DELETE FROM '.self::quote($table));
        foreach ($target['tables'] as $table=>$rows) {
            if (!$rows) continue;
            $columns = array_keys($rows[0]);
            $insert = $db->prepare('INSERT INTO '.self::quote($table).' ('.implode(',',array_map([self::class,'quote'],$columns)).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            foreach ($rows as $row) $insert->execute(array_values($row));
        }
        $actual = [];
        foreach ($db->query('SELECT name,seq FROM sqlite_sequence')->fetchAll() as $row) if (isset(self::TABLES[$row['name']])) $actual[$row['name']] = (int)$row['seq'];
        $delete = $db->prepare('DELETE FROM sqlite_sequence WHERE name=?');
        $insert = $db->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES (?,?)');
        foreach (array_unique([...array_keys($highWater),...array_keys($target['sequences']),...array_keys($actual)]) as $table) {
            $sequence = max($highWater[$table] ?? 0, $target['sequences'][$table] ?? 0, $actual[$table] ?? 0);
            $delete->execute([$table]); $insert->execute([$table,$sequence]);
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new DomainException('Snapshot verletzt Datenbankbeziehungen. Nichts wurde wiederhergestellt.');
    }

    private function buildPreview(int $id): array
    {
        $target = $this->load($id); $current = $this->capture();
        $revision = hash('sha256', self::encode([$current,$this->epoch()]));
        $check = (new NetworkCheck($this->cloneTarget($target['data'])))->run();
        $tables = $changes = []; $summary = ['added'=>0,'changed'=>0,'removed'=>0];
        foreach (self::TABLES as $table=>$label) {
            $keys = array_values(array_filter($this->columns($table), static fn($column)=>(int)$column['pk'] > 0));
            usort($keys, static fn($a,$b)=>(int)$a['pk'] <=>(int)$b['pk']);
            $index = static function(array $rows) use ($keys): array {
                $indexed = [];
                foreach ($rows as $row) $indexed[self::encode(array_map(static fn($column)=>$row[$column['name']],$keys))] = $row;
                return $indexed;
            };
            $before = $index($current['tables'][$table]); $after = $index($target['data']['tables'][$table]);
            $counts = ['added'=>0,'changed'=>0,'removed'=>0];
            foreach (array_unique([...array_keys($before),...array_keys($after)]) as $key) {
                $old = $before[$key] ?? null; $new = $after[$key] ?? null;
                if ($old === $new) continue;
                $action = $old === null ? 'added' : ($new === null ? 'removed' : 'changed'); $counts[$action]++; $summary[$action]++;
                if (count($changes) < 500) $changes[] = ['table'=>$table,'key'=>$key,'label'=>(string)($new['name'] ?? $old['name'] ?? $new['label'] ?? $old['label'] ?? $label.' '.$key),'before'=>$old,'after'=>$new];
            }
            $tables[] = ['table'=>$table,'label'=>$label,'before_count'=>count($before),'after_count'=>count($after)]+$counts;
        }
        $networkCheck = ['summary'=>$check['summary'],'findings'=>$check['findings']];
        $planHash = hash('sha256', self::encode([$id,$target['metadata']['payload_hash'],$revision,$networkCheck]));
        return ['snapshot'=>$target['metadata'],'revision'=>$revision,'plan_hash'=>$planHash,'summary'=>$summary,'tables'=>$tables,'changes'=>$changes,
            'changes_truncated'=>array_sum($summary)>count($changes),'network_check'=>$networkCheck,'requires_error_confirmation'=>$check['summary']['error']>0,'can_restore'=>true];
    }
}
