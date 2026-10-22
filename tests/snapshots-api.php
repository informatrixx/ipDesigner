<?php
declare(strict_types=1);

function snapshotApiRequest(string $path, string $resource, string $method = 'GET', ?string $epoch = null): array
{
    $session = 'ipdesigner-test-'.bin2hex(random_bytes(10));
    $code = <<<'PHP'
putenv('IPDESIGNER_DB_PATH='.$argv[1]);
$_SERVER['REQUEST_METHOD']=$argv[3]; $_SERVER['REQUEST_URI']='/codex/ipDesigner/api/'.$argv[2];
if ($argv[3] !== 'GET') {
    session_save_path(sys_get_temp_dir());session_id($argv[5]);session_start();
    $_SESSION['csrf']='fixture-token';$_SERVER['HTTP_X_CSRF_TOKEN']='fixture-token';
    if ($argv[4] !== '-') $_SERVER['HTTP_X_PLANNING_EPOCH']=$argv[4];
}
register_shutdown_function(static function(){fwrite(STDERR,(string)(http_response_code() ?: 200));});
require 'public/index.php';
PHP;
    $process=proc_open([PHP_BINARY,'-r',$code,$path,$resource,$method,$epoch ?? '-',$session],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('Snapshot-API-Testprozess konnte nicht starten.');
    try {
        fclose($pipes[0]);$json=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        equal(0,proc_close($process));
        if(!ctype_digit($status))throw new RuntimeException($status);
        return [(int)$status,json_decode($json,true,512,JSON_THROW_ON_ERROR)];
    } finally {@unlink(sys_get_temp_dir().'/sess_'.$session);}
}

test('Snapshot-Katalog läuft schreibfrei durch den Frontcontroller und enthält keine Payloads',function():void{
    $f=networkCheckFixture();$snapshots=new IpDesigner\PlanningSnapshots($f['db']);
    $snapshot=$snapshots->create(['name'=>'API-Sicherung']);
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-snapshot-api-');unlink($path);
    try {
        $f['db']->exec('VACUUM INTO '.$f['db']->quote($path));$before=hash_file('sha256',$path);
        [$status,$response]=snapshotApiRequest('file:'.$path.'?mode=ro','snapshots');
        equal(200,$status);equal($snapshot['id'],$response['data'][0]['id']);
        equal(false,array_key_exists('payload',$response['data'][0]));equal(false,array_key_exists('tables',$response['data'][0]));
        equal($before,hash_file('sha256',$path));equal(false,file_exists($path.'.planning.lock'));
    } finally {@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');}
});

test('Schema15 bleibt für bestehende API lesbar und fordert nur für Snapshots Migration an',function():void{
    $f=networkCheckFixture();$db=$f['db'];
    $db->exec('DROP TABLE planning_snapshots');$db->exec('DROP TABLE planning_state');$db->exec('DELETE FROM schema_migrations WHERE version=16');
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-snapshot-old-');unlink($path);
    try {
        $db->exec('VACUUM INTO '.$db->quote($path));$before=hash_file('sha256',$path);
        [$status,$response]=snapshotApiRequest('file:'.$path.'?mode=ro','snapshots');equal(503,$status);
        equal(true,str_contains($response['error']['message'],'php bin/migrate.php'));
        [$status]=snapshotApiRequest('file:'.$path.'?mode=ro','hosts');equal(200,$status);
        equal($before,hash_file('sha256',$path));
    } finally {@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');}
});

test('Frontcontroller weist fehlende und veraltete Epochen vor Schreiboperationen ab',function():void{
    $f=networkCheckFixture();$db=$f['db'];$epoch=IpDesigner\PlanningWriteGuard::epoch($db);
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-snapshot-guard-');unlink($path);
    try {
        $db->exec('VACUUM INTO '.$db->quote($path));
        foreach([null,'previous-epoch'] as $old) {
            foreach(['hosts','snapshots','snapshots/1/restore','topology/positions'] as $resource) {
                [$status,$response]=snapshotApiRequest($path,$resource,'POST',$old);equal(409,$status);
                equal(true,str_contains(mb_strtolower($response['error']['message']),'laden'));
            }
        }
        // A valid epoch reaches normal input validation (the CLI request has no JSON body).
        [$status]=snapshotApiRequest($path,'snapshots','POST',$epoch);equal(422,$status);
        $copy=IpDesigner\Database::connect($path);equal(0,(int)$copy->query('SELECT COUNT(*) FROM planning_snapshots')->fetchColumn());
        equal(2,(int)$copy->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    } finally {@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');@unlink($path.'.planning.lock');}
});
