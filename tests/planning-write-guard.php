<?php
declare(strict_types=1);

use IpDesigner\PlanningWriteGuard;

require_once dirname(__DIR__).'/src/PlanningWriteGuard.php';

/** Minimal isolated fixture; intentionally independent of the application's migrations. */
function planningGuardDatabase(string $path = ':memory:', bool $legacy = false): PDO
{
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY)');
    $db->exec('INSERT INTO schema_migrations VALUES ('.($legacy ? '15' : '16').')');
    if (!$legacy) {
        $db->exec('CREATE TABLE planning_state(id INTEGER PRIMARY KEY,restore_epoch TEXT NOT NULL)');
        $db->exec("INSERT INTO planning_state VALUES (1,'epoch-before')");
    }
    return $db;
}

function planningGuardCleanup(string $path): void
{
    foreach ([$path, $path.'-wal', $path.'-shm', $path.'.planning.lock', $path.'.alias'] as $file) if (file_exists($file) || is_link($file)) unlink($file);
}

/** The child announces readiness, then attempts the real guard on a separate connection. */
function planningGuardChild(string $path, string $epoch, bool $restore, int $timeoutMs): array
{
    $code = <<<'PHP'
require $argv[1];
$db = new PDO('sqlite:'.$argv[2], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "ready\n"; flush();
try {
    $guard = IpDesigner\PlanningWriteGuard::enter($db, $argv[3], $argv[4] === '1', (int)$argv[5]);
    echo json_encode(['allowed'=>true,'transaction'=>$db->inTransaction()]);
    $guard->release();
} catch (DomainException $error) {
    echo json_encode(['allowed'=>false,'message'=>$error->getMessage()]);
}
PHP;
    $pipes = [];
    $process = proc_open([PHP_BINARY,'-r',$code,dirname(__DIR__).'/src/PlanningWriteGuard.php',$path,$epoch,$restore?'1':'0',(string)$timeoutMs],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Guard-Testprozess konnte nicht gestartet werden.');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    equal("ready\n", fgets($pipes[1]));
    return [$process, $pipes];
}

function planningGuardChildResult(array $child): array
{
    [$process, $pipes] = $child;
    $output = stream_get_contents($pipes[1]);$errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    equal(0, proc_close($process), $errors ?: 'Guard-Testprozess fehlgeschlagen.');
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('Planungsschutz prüft aktuelle, fehlende und veraltete Epoch ohne SQL-Transaktion', function (): void {
    $db=planningGuardDatabase();equal('epoch-before',PlanningWriteGuard::epoch($db));
    $db->exec('PRAGMA query_only=ON');
    foreach ([null,'','epoch-old'] as $header) throws(fn()=>PlanningWriteGuard::enter($db,$header),DomainException::class);
    $guard=PlanningWriteGuard::enter($db,'epoch-before');equal(false,$db->inTransaction());
    $guard->release();$guard->release();
    $guard=PlanningWriteGuard::enter($db,'epoch-before',true);equal(false,$db->inTransaction());$guard->release();
    throws(fn()=>PlanningWriteGuard::enter($db,'epoch-before',false,5001),InvalidArgumentException::class);
    $db->beginTransaction();
    throws(fn()=>PlanningWriteGuard::enter($db,'epoch-before'),DomainException::class);
    equal(true,$db->inTransaction());$db->rollBack();
});

test('Planungsschutz lässt Schema 15 unverändert und erzeugt bei Epoch-Lesen keine Sperrdatei', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-guard-');
    try {
        $db=planningGuardDatabase($path,true);$before=hash_file('sha256',$path);
        equal(null,PlanningWriteGuard::epoch($db));
        $guard=PlanningWriteGuard::enter($db,null);$guard->release();
        equal(false,file_exists($path.'.planning.lock'));equal($before,hash_file('sha256',$path));
        $db->exec('CREATE TABLE planning_state(id INTEGER PRIMARY KEY,restore_epoch TEXT NOT NULL)');
        $db->exec("INSERT INTO planning_state VALUES (1,'epoch-before')");
        equal('epoch-before',PlanningWriteGuard::epoch($db));equal(false,file_exists($path.'.planning.lock'));
        $db=null;
    } finally { planningGuardCleanup($path); }
});

test('Planungsschutz weist fehlenden oder beschädigten Schema-16-State kontrolliert ab', function (): void {
    foreach (["DELETE FROM planning_state","UPDATE planning_state SET id=2","UPDATE planning_state SET restore_epoch=''",
        "UPDATE planning_state SET restore_epoch='bad epoch'","INSERT INTO planning_state VALUES (2,'other')",
        "ALTER TABLE planning_state RENAME COLUMN restore_epoch TO wrong_column","DROP TABLE planning_state",
        "DROP TABLE planning_state; CREATE VIEW planning_state AS SELECT 1 AS id,'epoch-before' AS restore_epoch"] as $sql) {
        $db=planningGuardDatabase();$db->exec($sql);
        throws(fn()=>PlanningWriteGuard::epoch($db),DomainException::class);
        throws(fn()=>PlanningWriteGuard::enter($db,'epoch-before'),DomainException::class);
    }
});

test('Gemeinsame Schreibsperren blockieren Restore und exklusive Restore-Sperren blockieren Writes', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-guard-');$guard=null;
    try {
        $db=planningGuardDatabase($path);symlink($path,$path.'.alias');
        $guard=PlanningWriteGuard::enter($db,'epoch-before');
        equal(true,planningGuardChildResult(planningGuardChild($path,'epoch-before',false,50))['allowed']);
        $blocked=planningGuardChildResult(planningGuardChild($path.'.alias','epoch-before',true,50));
        equal(false,$blocked['allowed']);equal(true,str_contains($blocked['message'],'kurz warten'));
        $guard->release();$guard=PlanningWriteGuard::enter($db,'epoch-before',true);
        $blocked=planningGuardChildResult(planningGuardChild($path,'epoch-before',false,50));
        equal(false,$blocked['allowed']);equal(true,str_contains($blocked['message'],'kurz warten'));
        $guard=null; // Destructor, not just explicit release, must unblock other requests.
        equal(true,planningGuardChildResult(planningGuardChild($path,'epoch-before',true,50))['allowed']);
        equal(false,file_exists($path.'.alias.planning.lock'));$db=null;
    } finally { $guard?->release();planningGuardCleanup($path); }
});

test('Wartender Write prüft nach Restore-Sperre die neue Epoch und wird abgewiesen', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-guard-');$guard=null;$child=null;
    try {
        $db=planningGuardDatabase($path);$guard=PlanningWriteGuard::enter($db,'epoch-before',true);
        $child=planningGuardChild($path,'epoch-before',false,2000);
        $read=[$child[1][1]];$write=$except=null;
        equal(0,stream_select($read,$write,$except,0,50000),'Write durfte die gehaltene Restore-Sperre nicht passieren.');
        $db->exec("UPDATE planning_state SET restore_epoch='epoch-after' WHERE id=1");
        $guard->release();$result=planningGuardChildResult($child);$child=null;
        equal(false,$result['allowed']);equal(true,str_contains($result['message'],'Seite neu laden'));
        $guard=PlanningWriteGuard::enter($db,'epoch-after');equal(false,$db->inTransaction());$guard->release();$db=null;
    } finally {
        $guard?->release();
        if ($child !== null) { proc_terminate($child[0]);planningGuardChildResult($child); }
        planningGuardCleanup($path);
    }
});
