<?php
declare(strict_types=1);

use IpDesigner\Csv;
use IpDesigner\HostCsvTransfer;

require_once dirname(__DIR__).'/src/Csv.php';
require_once dirname(__DIR__).'/src/HostCsvTransfer.php';

function csvInput(array $rows, array $columns = ['site','name','hostname','interface_name','network','ips']): array
{
    return ['csv'=>Csv::encode($columns,$rows),'allow_updates'=>false];
}

function csvApply(HostCsvTransfer $transfer, array $input): array
{
    $preview=$transfer->preview($input);
    equal([], $preview['errors']); equal(true,$preview['can_apply'],json_encode($preview['network_check']));
    return $transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);
}

test('CSV verarbeitet BOM, Trennzeichen, mehrzeilige Werte und Formelschutz reversibel', function (): void {
    foreach (['=1+1','+SUM(A1:A2)','-2','@cmd',"'Text",'  =cmd',"Text; mit \"Zitat\"\nzweite Zeile"] as $value) {
        $csv=Csv::encode(['description'],[['description'=>$value]]);
        equal(trim($value),Csv::decode($csv,['description'],['description'])[0]['values']['description']);
        if (in_array($value[0],['=','+','-','@',"'"],true)) equal(true,str_contains($csv,"'".$value));
    }
    equal('A',Csv::decode("sep=,\r\nname,hostname\r\nA,a\r\n",['name','hostname'],['name'])[0]['values']['name']);
    equal('a',Csv::decode("name\thostname\nA\ta",['name','hostname'],['name'])[0]['values']['hostname']);
    foreach (["name;name\nA;B", "unknown\nA", "name;hostname\nA", "name\n\"broken", "name\n\"A\"x", "name\nA\0", "name\n\xff"] as $bad) throws(fn()=>Csv::decode($bad,['name','hostname'],['name']),InvalidArgumentException::class);
    throws(fn()=>Csv::decode(str_repeat('a',Csv::MAX_BYTES+1),['name'],['name']),InvalidArgumentException::class);
    throws(fn()=>Csv::decode("name\n".str_repeat("A\n",Csv::MAX_ROWS+1),['name'],['name']),InvalidArgumentException::class);
});

test('CSV-Vorschau bleibt schreibfrei und übernimmt automatische IPs atomar', function (): void {
    $f=networkCheckFixture(); $db=$f['db'];$transfer=new HostCsvTransfer($db);
    $input=csvInput([
        ['site'=>'Testsite','name'=>'Neu','hostname'=>'neu','interface_name'=>'eth0','network'=>'LAN','ips'=>''],
        ['site'=>'Testsite','name'=>'Neu','hostname'=>'neu','interface_name'=>'eth1','network'=>'LAN','ips'=>'10.55.0.4 | 10.55.0.5'],
    ]);
    $before=$transfer->export()['csv'];$db->exec('PRAGMA query_only=ON');
    $preview=$transfer->preview($input);equal([], $preview['errors']);equal(true,$preview['can_apply']);
    equal(['10.55.0.3'],$preview['rows'][0]['after']['interface']['ips']);
    equal(['10.55.0.4','10.55.0.5'],$preview['rows'][1]['after']['interface']['ips']);
    equal($before,$transfer->export()['csv']);equal(2,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    $db->exec('PRAGMA query_only=OFF');
    $saved=$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);
    equal(true,$saved['saved']);equal(1,$saved['summary']['hosts_created']);equal(2,$saved['summary']['interfaces_created']);
    equal(3,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    throws(fn()=>$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
});

test('CSV-Export ist unverändert reimportierbar und erhält Aliasse, Notizen und Router', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$repo=$f['repo'];$host=$f['host'];
    $repo->saveHost(array_replace($host,['aliases'=>['alias'],'notes'=>'Nicht löschen','description'=>'=1+1']),(int)$host['id']);
    $transfer=new HostCsvTransfer($db);$csv=$transfer->export()['csv'];
    equal(true,str_contains($csv,"'=1+1"));
    equal(false,$transfer->preview(['csv'=>$csv])['can_apply']);
    $input=['csv'=>$csv,'allow_updates'=>true];$preview=$transfer->preview($input);
    equal([], $preview['errors']);equal(true,$preview['can_apply']);equal(0,array_sum($preview['summary']));
    $transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);
    equal($preview['revision'],$transfer->preview($input)['revision']);
    $current=array_column($repo->hosts(),null,'id')[$host['id']];equal('Nicht löschen',$current['notes']);equal('alias',$current['aliases'][0]['input_name']);
    equal(1,count($repo->routers()));
    equal(1,$transfer->export(['host_ids'=>(string)$host['id']])['hosts']);
});

test('CSV zeigt Zeilenfehler und speichert bei Konflikten oder manipuliertem Plan nichts', function (): void {
    $f=networkCheckFixture();$transfer=new HostCsvTransfer($f['db']);
    $input=csvInput([
        ['site'=>'Testsite','name'=>'Gültig','hostname'=>'valid','interface_name'=>'eth0','network'=>'LAN','ips'=>'10.55.0.3'],
        ['site'=>'Testsite','name'=>'Doppelt','hostname'=>'duplicate','interface_name'=>'eth0','network'=>'LAN','ips'=>'10.55.0.2'],
    ]);
    $preview=$transfer->preview($input);equal(false,$preview['can_apply']);equal(3,$preview['errors'][0]['record']);
    throws(fn()=>$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
    equal(2,(int)$f['db']->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    $good=csvInput([['site'=>'Testsite','name'=>'Gültig','hostname'=>'valid']]);$preview=$transfer->preview($good);
    throws(fn()=>$transfer->apply($good+['revision'=>$preview['revision'],'plan_hash'=>str_repeat('0',64)]),DomainException::class);
    equal(2,(int)$f['db']->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    $altered=csvInput([['site'=>'Testsite','name'=>'Andere CSV','hostname'=>'othercsv']]);
    throws(fn()=>$transfer->apply($altered+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
    equal(2,(int)$f['db']->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    $f['repo']->saveHost(['site_id'=>$f['site']['id'],'name'=>'Andere Änderung','hostname'=>'changed']);
    throws(fn()=>$transfer->apply($good+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
});

test('CSV aktualisiert explizite IDs und lehnt fremde Interfaces sowie widersprüchliche Hostzeilen ab', function (): void {
    $f=networkCheckFixture();$transfer=new HostCsvTransfer($f['db']);
    $columns=['host_id','site','name','hostname','description','interface_id','interface_name','network','ips'];
    $row=['host_id'=>$f['host']['id'],'site'=>'Testsite','name'=>'Umbenannt','hostname'=>'external.example.','description'=>'Neu',
        'interface_id'=>$f['interface']['id'],'interface_name'=>'eth0','network'=>'LAN','ips'=>'10.55.0.3'];
    $input=csvInput([$row],$columns);$input['allow_updates']=true;
    $saved=csvApply($transfer,$input);equal(1,$saved['summary']['hosts_updated']);equal(1,$saved['summary']['interfaces_updated']);
    $current=array_column($f['repo']->hosts(),null,'id')[$f['host']['id']];equal('external.example',$current['fqdn']);
    $bad=csvInput([array_replace($row,['interface_id'=>$f['gateway']['id']])],$columns);$bad['allow_updates']=true;
    equal(false,$transfer->preview($bad)['can_apply']);
    $bad=csvInput([array_replace($row,['interface_id'=>'','interface_name'=>'']),array_replace($row,['name'=>'Widerspruch','interface_id'=>'','interface_name'=>''])],$columns);$bad['allow_updates']=true;
    equal(false,$transfer->preview($bad)['can_apply']);
    $wrongNetwork=csvInput([['site'=>'Testsite','name'=>'Falsches Netz','hostname'=>'wrongnet','interface_name'=>'eth0','network_id'=>$f['network'],'network'=>'Falscher Name']],['site','name','hostname','interface_name','network_id','network']);
    equal(false,$transfer->preview($wrongNetwork)['can_apply']);
});

test('CSV unterstützt globale VPN-Interfaces und trennt gleichnamige Netze nach Site', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$repo=$f['repo'];$transfer=new HostCsvTransfer($db);
    $network=$repo->saveVirtualNetwork(['name'=>'VPN','network_type'=>'vpn','cidr'=>'10.99.0.0/24']);
    fixtureNetwork($db,(int)$f['space']['id'],(int)$f['otherSite']['id'],'LAN','10.55.1.0/29');
    $input=csvInput([['site'=>'Andere Site','name'=>'Neu','hostname'=>'neu','interface_name'=>'eth0','network'=>'LAN','ips'=>'']]);
    $saved=csvApply($transfer,$input);equal(['10.55.1.2'],$saved['rows'][0]['after']['interface']['ips']);
    $columns=['host_id','site','name','hostname','interface_type','interface_name','network','ips'];
    $input=csvInput([['host_id'=>$f['router']['id'],'site'=>'Testsite','name'=>'Gateway','hostname'=>'gateway',
        'interface_type'=>'vpn','interface_name'=>'wg0','network'=>'VPN','ips'=>'10.99.0.1 | 10.99.0.2']],$columns);$input['allow_updates']=true;
    csvApply($transfer,$input);equal(['10.99.0.1','10.99.0.2'],$repo->vpnInterfaces()[0]['ips']);
    csvApply($transfer,['csv'=>$transfer->export()['csv'],'allow_updates'=>true]);
});

test('CSV verhindert die Gateway-Übernahme durch neue Router auch bei automatischer IP', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$transfer=new HostCsvTransfer($db);
    $before=$db->query('SELECT * FROM gateway_bindings')->fetchAll();
    $columns=['site','name','hostname','type','is_router','interface_name','network','ips','is_gateway'];
    foreach ([['ips'=>'10.55.0.3','is_gateway'=>'1'],['ips'=>'','is_gateway'=>'0']] as $gateway) {
        $input=csvInput([array_merge(['site'=>'Testsite','name'=>'Anderer Router','hostname'=>'other-router',
            'type'=>'network','is_router'=>'1','interface_name'=>'lan0','network'=>'LAN'],$gateway)],$columns);
        $preview=$transfer->preview($input);
        equal(false,$preview['can_apply']);equal(1,count($preview['errors']));
        equal(true,str_contains($preview['errors'][0]['message'],'anderes Gateway-Interface'));
        throws(fn()=>$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
        equal($before,$db->query('SELECT * FROM gateway_bindings')->fetchAll());
        equal(2,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
        equal(2,(int)$db->query('SELECT COUNT(*) FROM network_interfaces')->fetchColumn());
        equal(IpDesigner\IpMath::toInt('10.55.0.1'),(int)$db->query('SELECT gateway_int FROM network_nodes WHERE id='.$f['network'])->fetchColumn());
    }
});

test('CSV schützt fremde Gateway-Bindungen bei Updates und erhält das eigene Gateway', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$repo=$f['repo'];$transfer=new HostCsvTransfer($db);
    $router=$repo->saveRouter(['site_id'=>$f['site']['id'],'name'=>'Anderer Router','hostname'=>'other-router']);
    $interface=$repo->saveInterface(['host_id'=>$router['id'],'network_id'=>$f['network'],'name'=>'lan1','ips'=>['10.55.0.3']]);
    $before=$db->query('SELECT * FROM gateway_bindings')->fetchAll();
    $columns=['host_id','site','name','hostname','interface_id','interface_name','network','ips','is_gateway'];
    foreach ([['ips'=>'10.55.0.3','is_gateway'=>'1'],['ips'=>'','is_gateway'=>'0']] as $gateway) {
        $input=csvInput([array_merge(['host_id'=>$router['id'],'site'=>'Testsite','name'=>$router['name'],
            'hostname'=>$router['hostname'],'interface_id'=>$interface['id'],'interface_name'=>'lan1','network'=>'LAN'],$gateway)],$columns);
        $input['allow_updates']=true;$preview=$transfer->preview($input);
        equal(false,$preview['can_apply']);equal(1,count($preview['errors']));
        equal(true,str_contains($preview['errors'][0]['message'],'anderes Gateway-Interface'));
        throws(fn()=>$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
        equal($before,$db->query('SELECT * FROM gateway_bindings')->fetchAll());
        equal(['10.55.0.3'],$repo->interfaces((int)$router['id'])[0]['ips']);
    }
    $input=['csv'=>$transfer->export()['csv'],'allow_updates'=>true];$preview=$transfer->preview($input);
    csvApply($transfer,$input);equal($preview['revision'],$transfer->preview($input)['revision']);
    $input=csvInput([['host_id'=>$f['router']['id'],'site'=>'Testsite','name'=>'Gateway','hostname'=>'gateway',
        'interface_id'=>$f['gateway']['id'],'interface_name'=>'lan0','network'=>'LAN','ips'=>'','is_gateway'=>'1']],$columns);
    $input['allow_updates']=true;$saved=csvApply($transfer,$input);
    equal(0,array_sum($saved['summary']));equal($before,$db->query('SELECT * FROM gateway_bindings')->fetchAll());
});

test('CSV bindet freie Gateways und verhindert ihre Übernahme innerhalb derselben Datei', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$transfer=new HostCsvTransfer($db);
    $network=fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Neues LAN','10.55.1.0/29');
    $columns=['site','name','hostname','type','is_router','interface_name','network','ips','is_gateway'];
    $rows=[['site'=>'Testsite','name'=>'Neues Gateway','hostname'=>'new-gateway','type'=>'network','is_router'=>'1',
        'interface_name'=>'lan1','network'=>'Neues LAN','ips'=>'','is_gateway'=>'0'],
        ['site'=>'Testsite','name'=>'Fremdes Gateway','hostname'=>'foreign-gateway','type'=>'network','is_router'=>'1',
        'interface_name'=>'lan1','network'=>'Neues LAN','ips'=>'10.55.1.3','is_gateway'=>'1']];
    $input=csvInput($rows,$columns);$preview=$transfer->preview($input);
    equal(false,$preview['can_apply']);equal(1,count($preview['errors']));equal(3,$preview['errors'][0]['record']);
    equal(true,str_contains($preview['errors'][0]['message'],'anderes Gateway-Interface'));
    throws(fn()=>$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);
    equal(0,(int)$db->query('SELECT COUNT(*) FROM gateway_bindings WHERE network_id='.$network)->fetchColumn());
    equal(2,(int)$db->query('SELECT COUNT(*) FROM hosts')->fetchColumn());
    $saved=csvApply($transfer,csvInput([$rows[0]],$columns));equal(['10.55.1.1'],$saved['rows'][0]['after']['interface']['ips']);
    equal(1,(int)$db->query('SELECT COUNT(*) FROM gateway_bindings WHERE network_id='.$network)->fetchColumn());
});

test('CSV prüft Pools, DHCP-Bestätigung und Netzcheck vor dem Speichern', function (): void {
    $f=networkCheckFixture();$db=$f['db'];$transfer=new HostCsvTransfer($db);
    $l3=fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'Clients','10.55.2.0/24',['node_type'=>'container','l3_enabled'=>1]);
    fixtureNetwork($db,(int)$f['space']['id'],(int)$f['site']['id'],'DHCP','10.55.2.128/25',['parent_id'=>$l3,'allocation_type'=>'dhcp_pool']);
    $columns=['site','name','hostname','interface_name','network','pool','ips','confirm_dhcp_overlap'];
    $row=['site'=>'Testsite','name'=>'Client','hostname'=>'client','interface_name'=>'eth0','network'=>'Clients','pool'=>'DHCP','ips'=>'10.55.2.130'];
    equal(false,$transfer->preview(csvInput([$row],$columns))['can_apply']);
    $input=csvInput([array_replace($row,['confirm_dhcp_overlap'=>'1'])],$columns);$preview=$transfer->preview($input);
    equal(true,$preview['can_apply']);equal(true,$preview['network_check']['summary']['info']>0);csvApply($transfer,$input);
    $bad=csvInput([array_replace($row,['name'=>'Falsch','hostname'=>'bad','confirm_dhcp_overlap'=>'1','ips'=>'10.55.2.10'])],$columns);
    equal(false,$transfer->preview($bad)['can_apply']);
    $db->exec('UPDATE network_nodes SET prefix=20 WHERE id='.$l3);
    $check=$transfer->preview(csvInput([['site'=>'Testsite','name'=>'Nur Host','hostname'=>'justhost']]));
    equal(false,$check['can_apply']);equal(true,$check['network_check']['summary']['error']>0);
});
