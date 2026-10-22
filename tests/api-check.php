<?php
declare(strict_types=1);

test('GET Netzcheck läuft über den Frontcontroller mit schreibgeschützter Datenbank', function (): void {
    $fixture = networkCheckFixture();
    $path = tempnam(sys_get_temp_dir(),'ipdesigner-api-');
    unlink($path); // VACUUM INTO creates its target itself.
    try {
        $fixture['db']->exec('VACUUM INTO '.$fixture['db']->quote($path));
        $before = hash_file('sha256',$path);
        $code = 'putenv("IPDESIGNER_DB_PATH=".$argv[1]); $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/codex/ipDesigner/api/network-check"; require "public/index.php";';
        $process = proc_open([PHP_BINARY,'-r',$code,'file:'.$path.'?mode=ro'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('API-Testprozess konnte nicht starten.');
        fclose($pipes[0]); $json=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        equal(0,proc_close($process),$errors);equal('',$errors);
        $response=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        equal(['error'=>0,'warning'=>0,'info'=>0],$response['data']['summary']);
        equal($before,hash_file('sha256',$path));
    } finally {
        @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
    }
});

test('Fehlendes Schema liefert eine kontrollierte API-Meldung statt einer Migration', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-empty-');
    try {
        $code='putenv("IPDESIGNER_DB_PATH=".$argv[1]); $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/api/network-check"; register_shutdown_function(static function(){fwrite(STDERR,(string)http_response_code());}); require "public/index.php";';
        $process=proc_open([PHP_BINARY,'-r',$code,$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if(!is_resource($process))throw new RuntimeException('API-Testprozess konnte nicht starten.');
        fclose($pipes[0]);$json=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(0,proc_close($process));
        equal('503',$status);equal(true,str_contains(json_decode($json,true,512,JSON_THROW_ON_ERROR)['error']['message'],'php bin/migrate.php'));
        $db=IpDesigner\Database::connect($path);equal(0,(int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
    } finally {@unlink($path);}
});
