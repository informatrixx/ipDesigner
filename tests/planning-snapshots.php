<?php
declare(strict_types=1);

use IpDesigner\PlanningSnapshots;

require_once dirname(__DIR__).'/src/PlanningSnapshots.php';

function snapshotRestore(PlanningSnapshots $snapshots, int $id, array $extra = []): array
{
    $preview = $snapshots->preview($id);
    return $snapshots->restore($id, $extra+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash'],'confirm_restore'=>true]);
}

function snapshotPayload(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT payload FROM planning_snapshots WHERE id=?'); $stmt->execute([$id]);
    return json_decode($stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
}

function snapshotDataEquals(PDO $db, array $payload): void
{
    foreach ($payload['tables'] as $table=>$expected) {
        $actual = $db->query('SELECT * FROM "'.$table.'"')->fetchAll();
        $sort = static function(array $rows): array { $rows = array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows); sort($rows); return $rows; };
        equal($sort($expected),$sort($actual),'Snapshot-Tabelle stimmt nicht überein: '.$table);
    }
}

function snapshotRichFixture(): array
{
    $f = networkCheckFixture(); $db = $f['db']; $repo = $f['repo'];
    $repo->saveHost(array_replace($f['host'], ['hostname'=>'external.example.','aliases'=>['alias'],'notes'=>'Erhaltene Notiz']), (int)$f['host']['id']);
    $db->prepare('INSERT INTO network_interface_addresses(interface_id,ip_int) VALUES (?,?)')->execute([$f['interface']['id'],IpDesigner\IpMath::toInt('10.55.0.3')]);
    $db->prepare('INSERT INTO network_reservations(network_id,ip_int,label) VALUES (?,?,?)')->execute([$f['network'],IpDesigner\IpMath::toInt('10.55.0.4'),'Drucker']);
    $l3 = fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Clients','10.55.2.0/24',['node_type'=>'container','l3_enabled'=>1]);
    $pool = fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Statisch','10.55.2.0/25',['parent_id'=>$l3,'allocation_type'=>'static_pool']);
    $repo->saveInterface(['host_id'=>$f['host']['id'],'network_id'=>$l3,'pool_id'=>$pool,'name'=>'eth1','ip'=>'10.55.2.5']);
    $vpn = $repo->saveVirtualNetwork(['name'=>'VPN','network_type'=>'vpn','cidr'=>'10.99.0.0/24']);
    $repo->saveVpnInterface(['router_id'=>$f['router']['id'],'virtual_network_id'=>$vpn['id'],'name'=>'wg0','ips'=>['10.99.0.1','10.99.0.2']]);
    $repo->saveVirtualNetwork(['name'=>'Docker','network_type'=>'docker_bridge','cidr'=>'172.18.0.0/16','owner_host_id'=>$f['host']['id']]);
    $repo->saveStaticRoute(['router_id'=>$f['router']['id'],'destination_cidr'=>'10.55.2.0/24','destination_network_id'=>$l3,'egress_interface_id'=>$f['gateway']['id'],'next_hop'=>'10.55.0.2']);
    $repo->saveService(['host_id'=>$f['host']['id'],'name'=>'Web','protocol'=>'tcp','port_start'=>443]);
    $repo->saveRelation(['source_id'=>$f['router']['id'],'target_id'=>$f['host']['id'],'type'=>'connected','label'=>'LAN']);
    $db->prepare('INSERT INTO topology_positions(entity_id,scope,x,y) VALUES (?,?,?,?)')->execute([$f['host']['id'],'global',123.5,-40]);
    $db->prepare('INSERT INTO topology_positions(entity_id,scope,x,y) VALUES (?,?,?,?)')->execute([$f['host']['id'],'site:'.$f['site']['id'],4,10]);
    $db->prepare('INSERT INTO replan_runs(address_space_id,revision_before,revision_after,before_snapshot,after_snapshot) VALUES (?,?,?,?,?)')->execute([$f['space']['id'],'before','after','{}','{}']);
    $block = $repo->saveBlock(['site_id'=>$f['site']['id'],'name'=>'Altbestand','cidr'=>'172.31.0.0/24']);
    $subnet = $repo->saveSubnet(['block_id'=>$block['id'],'name'=>'Alt-LAN','cidr'=>'172.31.0.0/25']);
    $db->prepare('INSERT INTO interfaces(host_id,subnet_id,name,ip_int) VALUES (?,?,?,?)')->execute([$f['host']['id'],$subnet['id'],'legacy0',IpDesigner\IpMath::toInt('172.31.0.2')]);
    $db->prepare('INSERT INTO reservations(subnet_id,ip_int,label) VALUES (?,?,?)')->execute([$subnet['id'],IpDesigner\IpMath::toInt('172.31.0.3'),'Altreservierung']);
    return $f;
}

test('Snapshots sichern alle 22 Planungstabellen und stellen samt Rettungssnapshot atomar wieder her', function (): void {
    $f = snapshotRichFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db);
    $saved = $snapshots->create(['name'=>'Vollständiger Plan','description'=>'Mit Altbestand, DNS, VPN, Pools und Diensten']);
    equal(22,$saved['summary']['tables']); equal(2,$saved['summary']['hosts']); equal(false,isset($saved['payload']));
    $payload = snapshotPayload($db,(int)$saved['id']);
    foreach ($payload['tables'] as $table=>$rows) equal(true,count($rows)>0,'Fixture deckt Tabelle nicht ab: '.$table);
    $db->exec("UPDATE hosts SET description='Verändert'; UPDATE services SET description='Anderer Dienst'; UPDATE topology_positions SET x=999");
    $f['repo']->saveHost(['site_id'=>$f['site']['id'],'name'=>'Nachträglich','hostname'=>'later']);
    $changed = $snapshots->create(['name'=>'Nachträglicher Stand']); $changedData = snapshotPayload($db,(int)$changed['id']);
    $epoch = $snapshots->epoch(); $result = snapshotRestore($snapshots,(int)$saved['id'],['confirm_errors'=>true]);
    equal(true,$result['restored']); equal('pre_restore',$result['rescue_snapshot']['kind']); equal((int)$saved['id'],(int)$result['rescue_snapshot']['source_snapshot_id']);
    equal(false,$epoch===$result['epoch']); equal(3,count($snapshots->list()));
    snapshotDataEquals($db,$payload); equal([],$db->query('PRAGMA foreign_key_check')->fetchAll());
    snapshotRestore($snapshots,(int)$result['rescue_snapshot']['id'],['confirm_errors'=>true]); snapshotDataEquals($db,$changedData);
    equal(4,count($snapshots->list())); equal(16,(int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn());
});

test('Snapshot-Vorschau und Liste sind schreibfrei, stabil und begrenzen ausschließlich Detailänderungen', function (): void {
    $f = networkCheckFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db);
    $saved = $snapshots->create(['name'=>'Basis']);
    $db->beginTransaction();
    for ($i=0; $i<260; $i++) {
        $db->exec("INSERT INTO entities(kind) VALUES ('host')");
        $db->prepare('INSERT INTO hosts(id,site_id,name,hostname) VALUES (?,?,?,?)')->execute([(int)$db->lastInsertId(),$f['site']['id'],'Bulk '.$i,'bulk'.$i]);
    }
    $db->commit(); $db->exec('PRAGMA query_only=ON');
    $preview = $snapshots->preview((int)$saved['id']); $again = $snapshots->preview((int)$saved['id']);
    equal(520,$preview['summary']['removed']); equal(500,count($preview['changes'])); equal(true,$preview['changes_truncated']); equal(22,count($preview['tables']));
    equal($preview['plan_hash'],$again['plan_hash']); equal($preview['revision'],$again['revision']); equal(1,count($snapshots->list()));
    equal(262,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
});

test('Snapshots verlangen Bestätigung und lehnen veraltete oder manipulierte Vorschauen ab', function (): void {
    $f = networkCheckFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db); $saved = $snapshots->create(['name'=>'Basis']);
    $preview = $snapshots->preview((int)$saved['id']); $input = ['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash'],'confirm_restore'=>true];
    throws(fn()=>$snapshots->restore((int)$saved['id'],array_replace($input,['confirm_restore'=>false])),InvalidArgumentException::class);
    throws(fn()=>$snapshots->restore((int)$saved['id'],array_replace($input,['plan_hash'=>str_repeat('0',64)])),DomainException::class);
    $db->prepare('INSERT INTO topology_positions(entity_id,scope,x,y) VALUES (?,?,?,?)')->execute([$f['host']['id'],'global',1,2]);
    throws(fn()=>$snapshots->restore((int)$saved['id'],$input),DomainException::class);
    equal(1,count($snapshots->list()));
    $fresh = $snapshots->preview((int)$saved['id']); $freshInput = ['revision'=>$fresh['revision'],'plan_hash'=>$fresh['plan_hash'],'confirm_restore'=>true];
    $snapshots->restore((int)$saved['id'],$freshInput);
    throws(fn()=>$snapshots->restore((int)$saved['id'],$input),DomainException::class);
    equal(2,count($snapshots->list()));
    throws(fn()=>$snapshots->preview(999999),DomainException::class);
    foreach ([['name'=>''],['name'=>str_repeat('a',121)],['name'=>[]],['name'=>"bad\0name"],['name'=>'x','description'=>str_repeat('a',2001)]] as $bad) throws(fn()=>$snapshots->create($bad),InvalidArgumentException::class);
});

test('Netzcheck-Fehler eines Snapshots benötigen eigene Bestätigung und werden nicht repariert', function (): void {
    $f = networkCheckFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db);
    $db->exec('UPDATE network_nodes SET prefix=20 WHERE id='.(int)$f['network']);
    $saved = $snapshots->create(['name'=>'Historischer fehlerhafter Plan']);
    $db->exec('UPDATE network_nodes SET prefix=29 WHERE id='.(int)$f['network']);
    $preview = $snapshots->preview((int)$saved['id']); equal(true,$preview['requires_error_confirmation']); equal(true,$preview['can_restore']);
    throws(fn()=>snapshotRestore($snapshots,(int)$saved['id']),DomainException::class);
    equal(29,(int)$db->query('SELECT prefix FROM network_nodes WHERE id='.(int)$f['network'])->fetchColumn());
    snapshotRestore($snapshots,(int)$saved['id'],['confirm_errors'=>true]);
    equal(20,(int)$db->query('SELECT prefix FROM network_nodes WHERE id='.(int)$f['network'])->fetchColumn());
});

test('Snapshot-Prüfsummen, unbekannte Formate, Schema und Fremdschlüssel werden strikt geprüft', function (): void {
    foreach (['checksum','format','table','column','fk','schema'] as $kind) {
        $f = networkCheckFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db); $saved = $snapshots->create(['name'=>'Basis']);
        $data = snapshotPayload($db,(int)$saved['id']);
        if ($kind==='format') $data['format_version']=99;
        if ($kind==='table') unset($data['tables']['dns_names']);
        if ($kind==='column') $data['tables']['hosts'][0]['unexpected']='x';
        if ($kind==='fk') $data['tables']['network_interfaces'][0]['host_id']=999999;
        if ($kind==='schema') $data['schema_version']=99;
        $payload=json_encode($data,JSON_THROW_ON_ERROR); $hash=$kind==='checksum'?str_repeat('0',64):hash('sha256',$payload);
        $db->prepare('UPDATE planning_snapshots SET payload=?,payload_hash=? WHERE id=?')->execute([$payload,$hash,$saved['id']]);
        throws(fn()=>$snapshots->preview((int)$saved['id']),DomainException::class); equal(1,count($snapshots->list())); equal(2,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    }
    $f=networkCheckFixture(); $db=$f['db']; $snapshots=new PlanningSnapshots($db); $db->exec('CREATE TABLE future_planning_data(id INTEGER PRIMARY KEY)');
    throws(fn()=>$snapshots->create(['name'=>'Unvollständig']),DomainException::class); equal(0,count($snapshots->list()));
});

test('Restore-Fehler rollen Daten, Rettungssnapshot, Epoch und Sequenzen vollständig zurück', function (): void {
    $f = networkCheckFixture(); $db = $f['db']; $snapshots = new PlanningSnapshots($db);
    // This metadata trigger fires last, after the data replacement and rescue insert.
    $db->exec("CREATE TRIGGER test_restore_abort BEFORE UPDATE ON planning_state BEGIN SELECT RAISE(ABORT,'forced restore failure'); END");
    $saved = $snapshots->create(['name'=>'Basis']); $db->exec("UPDATE hosts SET description='Behalten'");
    $before = $snapshots->create(['name'=>'Vor Versuch']); $beforeData=snapshotPayload($db,(int)$before['id']); $epoch=$snapshots->epoch();
    $sequences=$db->query('SELECT * FROM sqlite_sequence ORDER BY name')->fetchAll();
    throws(fn()=>snapshotRestore($snapshots,(int)$saved['id']),PDOException::class);
    snapshotDataEquals($db,$beforeData); equal($epoch,$snapshots->epoch()); equal(2,count($snapshots->list()));
    equal($sequences,$db->query('SELECT * FROM sqlite_sequence ORDER BY name')->fetchAll()); equal(false,$db->inTransaction());
});

test('Restore weist stille Zieländerungen durch Domain- und Epochtrigger vollständig zurück', function (): void {
    foreach (['domain','epoch','epoch_fk','epoch_reset','epoch_delete'] as $trigger) {
        $f=networkCheckFixture(); $db=$f['db']; $snapshots=new PlanningSnapshots($db);
        $sql=match ($trigger) {
            'domain'=>"CREATE TRIGGER test_restore_mutation AFTER INSERT ON hosts BEGIN UPDATE hosts SET description='trigger changed restored data' WHERE id=NEW.id; END",
            'epoch'=>"CREATE TRIGGER test_restore_mutation AFTER UPDATE ON planning_state BEGIN UPDATE hosts SET description='epoch changed restored data'; END",
            'epoch_fk'=>"CREATE TRIGGER test_restore_mutation AFTER UPDATE ON planning_state BEGIN UPDATE network_interfaces SET host_id=999999; END",
            'epoch_reset'=>"CREATE TRIGGER test_restore_mutation AFTER UPDATE ON planning_state BEGIN UPDATE planning_state SET restore_epoch=OLD.restore_epoch WHERE id=1; END",
            'epoch_delete'=>"CREATE TRIGGER test_restore_mutation AFTER UPDATE ON planning_state BEGIN DELETE FROM planning_state WHERE id=1; END",
        };
        $db->exec($sql);
        $saved=$snapshots->create(['name'=>'Exakter Zielstand']); $payload=snapshotPayload($db,(int)$saved['id']);
        $epoch=$snapshots->epoch(); $sequences=$db->query('SELECT * FROM sqlite_sequence ORDER BY name')->fetchAll();
        $preview=$snapshots->preview((int)$saved['id']); equal(0,array_sum($preview['summary']));
        throws(fn()=>snapshotRestore($snapshots,(int)$saved['id']),$trigger==='epoch_delete'?IpDesigner\SchemaNotReady::class:DomainException::class);
        snapshotDataEquals($db,$payload); equal(1,count($snapshots->list())); equal($epoch,$snapshots->epoch());
        equal($sequences,$db->query('SELECT * FROM sqlite_sequence ORDER BY name')->fetchAll()); equal([],$db->query('PRAGMA foreign_key_check')->fetchAll());
        equal(false,$db->inTransaction());
    }
});

test('Snapshots bewahren ID-Hochwasser und CSV-Vorschauen ignorieren Katalogänderungen, nicht Restores', function (): void {
    $f=networkCheckFixture(); $db=$f['db']; $snapshots=new PlanningSnapshots($db); $csv=new IpDesigner\HostCsvTransfer($db);
    $input=['csv'=>"site;name;hostname\nTestsite;CSV-Neu;csv-new",'allow_updates'=>false]; $beforeCsv=$csv->preview($input);
    $saved=$snapshots->create(['name'=>'Basis']); equal($beforeCsv['revision'],$csv->preview($input)['revision']);
    $new=$f['repo']->saveHost(['site_id'=>$f['site']['id'],'name'=>'Später','hostname'=>'later']); $high=(int)$new['id'];
    snapshotRestore($snapshots,(int)$saved['id']);
    $next=$f['repo']->saveHost(['site_id'=>$f['site']['id'],'name'=>'Danach','hostname'=>'after']); equal(true,(int)$next['id']>$high);
    snapshotRestore($snapshots,(int)$saved['id']); $afterCsv=$csv->preview($input); equal(false,$beforeCsv['revision']===$afterCsv['revision']);
    throws(fn()=>$csv->apply($input+['revision'=>$beforeCsv['revision'],'plan_hash'=>$beforeCsv['plan_hash']]),DomainException::class);
    // Same-data restore must invalidate even without an ID/row change.
    $current=$csv->preview($input); snapshotRestore($snapshots,(int)$saved['id']); equal(false,$current['revision']===$csv->preview($input)['revision']);
    equal(4,count($snapshots->list()));
});

test('Snapshot-Migration ist explizit und idempotent, Schema15 bleibt für vorhandene Funktionen bereit', function (): void {
    $f=networkCheckFixture(); $db=$f['db']; $snapshots=new PlanningSnapshots($db); $epoch=$snapshots->epoch();
    IpDesigner\Database::migrate($db); equal($epoch,$snapshots->epoch());
    $db->exec('DROP TABLE planning_snapshots; DROP TABLE planning_state; DELETE FROM schema_migrations WHERE version=16');
    IpDesigner\Database::assertReady($db); equal(2,count($f['repo']->hosts()));
    throws(fn()=>$snapshots->list(),IpDesigner\SchemaNotReady::class);
    throws(fn()=>$snapshots->create(['name'=>'Nicht migriert']),IpDesigner\SchemaNotReady::class);
    $csv=new IpDesigner\HostCsvTransfer($db); equal(2,$csv->export()['hosts']);
    IpDesigner\Database::migrate($db); equal([], $snapshots->list()); equal(32,strlen($snapshots->epoch()));
});

test('Snapshot-Restore erkennt konkurrierende Writes und hält bei fremdem Write-Lock vollständig an', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-snapshot-race-');
    try {
        $a=IpDesigner\Database::connect($path); IpDesigner\Database::migrate($a); $repoA=new IpDesigner\Repository($a);
        $site=$repoA->saveSite(['name'=>'Nebenläufig','domain_name'=>'race.example']);
        $repoA->saveHost(['site_id'=>$site['id'],'name'=>'Original','hostname'=>'original']);
        $snapshots=new PlanningSnapshots($a); $saved=$snapshots->create(['name'=>'Vor Konkurrenz']); $preview=$snapshots->preview((int)$saved['id']);
        $input=['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash'],'confirm_restore'=>true];
        $b=IpDesigner\Database::connect($path); $repoB=new IpDesigner\Repository($b);
        $repoB->saveHost(['site_id'=>$site['id'],'name'=>'Andere Verbindung','hostname'=>'concurrent']);
        throws(fn()=>$snapshots->restore((int)$saved['id'],$input),DomainException::class);
        equal(2,count($repoA->hosts())); equal(1,count($snapshots->list()));
        $fresh=$snapshots->preview((int)$saved['id']); $input=['revision'=>$fresh['revision'],'plan_hash'=>$fresh['plan_hash'],'confirm_restore'=>true];
        $a->exec('PRAGMA busy_timeout=1'); $b->exec('BEGIN IMMEDIATE');
        throws(fn()=>$snapshots->restore((int)$saved['id'],$input),PDOException::class);
        equal(false,$a->inTransaction()); equal(2,count($repoB->hosts())); equal(1,count($snapshots->list()));
        $b->commit(); $snapshots->restore((int)$saved['id'],$input); equal(1,count($repoB->hosts())); equal(2,count($snapshots->list()));
    } finally {
        if (isset($a) && $a->inTransaction()) $a->rollBack();
        if (isset($b) && $b->inTransaction()) $b->rollBack();
        unset($repoA,$repoB,$snapshots,$a,$b);
        @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm');
    }
});
