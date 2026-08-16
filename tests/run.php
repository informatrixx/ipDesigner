<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/IpMath.php';
require_once dirname(__DIR__) . '/src/DnsName.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Repository.php';
require_once dirname(__DIR__) . '/src/NetworkPlanner.php';
require_once dirname(__DIR__) . '/src/DynamicReplanner.php';
require_once dirname(__DIR__) . '/src/Ipv4Calculator.php';
require_once dirname(__DIR__) . '/src/Http.php';

use IpDesigner\Database;
use IpDesigner\DnsName;
use IpDesigner\IpMath;
use IpDesigner\NetworkPlanner;
use IpDesigner\DynamicReplanner;
use IpDesigner\Repository;
use IpDesigner\Ipv4Calculator;
use function IpDesigner\Http\detectBasePath;

$tests = [];
function test(string $name, callable $callback): void { global $tests; $tests[$name] = $callback; }
function equal(mixed $expected, mixed $actual, string $message = ''): void { if ($expected !== $actual) throw new RuntimeException($message ?: 'Erwartet '.var_export($expected,true).', erhalten '.var_export($actual,true)); }
function throws(callable $callback, string $class): void { try { $callback(); } catch (Throwable $error) { if ($error instanceof $class) return; throw $error; } throw new RuntimeException("Erwartete Exception $class wurde nicht ausgelöst."); }

test('IPv4- und CIDR-Mathematik', function (): void {
    $net = IpMath::parseCidr('192.168.10.0/24');
    equal(254, $net['usable']); equal('192.168.10.255', IpMath::toIp($net['broadcast']));
    equal(2, IpMath::parseCidr('10.0.0.0/31')['usable']);
    equal(1, IpMath::parseCidr('10.0.0.1/32')['usable']);
    equal(32, IpMath::prefixForHosts(1)); equal(31, IpMath::prefixForHosts(2)); equal(25, IpMath::prefixForHosts(120));
    throws(fn() => IpMath::parseCidr('192.168.10.2/24'), InvalidArgumentException::class);
});

test('IPv4-Rechner analysiert, dimensioniert, bündelt und teilt Netze', function (): void {
    $calculator = new Ipv4Calculator();
    $analysis = $calculator->calculate(['mode'=>'analyze','cidr'=>'192.168.10.25/24']);
    equal('192.168.10.0/24', $analysis['cidr']); equal('255.255.255.0', $analysis['netmask']); equal('0.0.0.255', $analysis['wildcard']); equal(true, $analysis['normalized']);
    $pointToPoint = $calculator->calculate(['mode'=>'analyze','cidr'=>'10.0.0.0/31']); equal(2, $pointToPoint['usable']);
    $hosts = $calculator->calculate(['mode'=>'hosts','hosts'=>100,'reserve_percent'=>20]); equal(120, $hosts['planned_hosts']); equal('/25', $hosts['prefix_label']); equal(6, $hosts['remaining']);
    $range = $calculator->calculate(['mode'=>'range','start_ip'=>'192.168.1.5','end_ip'=>'192.168.1.14']);
    equal(['192.168.1.5/32','192.168.1.6/31','192.168.1.8/30','192.168.1.12/31','192.168.1.14/32'], $range['exact_cidrs']); equal('192.168.1.0/28', $range['cover_cidr']); equal(6, $range['cover_extra']);
    $split = $calculator->calculate(['mode'=>'split','cidr'=>'10.0.0.0/24','target_prefix'=>26]); equal(4, $split['count']); equal('10.0.0.192/26', $split['children'][3]['cidr']);
    throws(fn()=>$calculator->calculate(['mode'=>'split','cidr'=>'0.0.0.0/0','target_prefix'=>32]), InvalidArgumentException::class);
    throws(fn()=>$calculator->calculate(['mode'=>'range','start_ip'=>'10.0.0.2','end_ip'=>'10.0.0.1']), InvalidArgumentException::class);
});

test('Unterpfad wird aus der nginx-URL erkannt', function (): void {
    equal('/codex/ipDesigner', detectBasePath('/codex/ipDesigner'));
    equal('/codex/ipDesigner', detectBasePath('/codex/ipDesigner/api/dashboard?x=1'));
    equal('', detectBasePath('/api/dashboard'));
});

test('DNS-Namen werden normalisiert und validiert', function (): void {
    equal('mail-01.wien.example.at', DnsName::fqdn('Mail-01', 'WIEN.Example.AT.'));
    equal('xn--mnchen-3ya.example', DnsName::domain('München.example'));
    equal('www.wien.example.at', DnsName::alias('www', 'wien.example.at')['fqdn']);
    equal('extern.example.net', DnsName::alias('extern.example.net.', 'wien.example.at')['fqdn']);
    $absolute = DnsName::primary('Office.Autismus-Asperger.at.', 'wien.example.at');
    equal('office.autismus-asperger.at.', $absolute['hostname']);
    equal('office.autismus-asperger.at', $absolute['fqdn']);
    equal(1, $absolute['is_fqdn']);
    equal('office.wien.example.at', DnsName::primary('Office', 'wien.example.at')['fqdn']);
    throws(fn() => DnsName::hostname('-ungueltig'), InvalidArgumentException::class);
    throws(fn() => DnsName::primary('office.autismus-asperger.at', 'wien.example.at'), InvalidArgumentException::class);
});

test('CRUD, Belegung und globale Konflikte', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ipdesigner-test-');
    $pdo=Database::connect($path);$repo = new Repository($pdo);$planner=new NetworkPlanner($pdo);
    $site = $repo->saveSite(['name'=>'Wien','domain_name'=>'wien.example.at']);
    $other = $repo->saveSite(['name'=>'Graz','domain_name'=>'graz.example.at']);
    $space=$planner->saveSpace(['name'=>'Intern','start_ip'=>'10.20.0.0']);equal(0,count($planner->networks()));
    $proposal=$planner->recommendNetwork(['address_space_id'=>$space['id'],'site_id'=>$site['id'],'name'=>'Server','node_type'=>'leaf','requested_hosts'=>100]);
    equal('10.20.0.0/25',$proposal['cidr']);equal(125,$proposal['host_capacity']);equal(0,count($planner->networks()));
    $subnet=$planner->applyNetwork(['input'=>$proposal['input'],'expected_cidr'=>$proposal['cidr'],'revision'=>$proposal['revision']]);
    $placed=$planner->applyHost(['network_id'=>$subnet['id'],'host'=>['name'=>'Webserver','hostname'=>'web-01','status'=>'active','aliases'=>['www','extern.example.net']]]);$host=$placed['host'];
    equal('web-01.wien.example.at', $host['fqdn']); equal(2, count($host['aliases']));
    $absoluteHost=$repo->saveHost(['site_id'=>$site['id'],'name'=>'Office cloud','hostname'=>'office.autismus-asperger.at.']);
    equal('office.autismus-asperger.at.', $absoluteHost['hostname']);
    equal('office.autismus-asperger.at', $absoluteHost['fqdn']);
    throws(fn() => $repo->saveHost(['site_id'=>$site['id'],'name'=>'Webserver 2','hostname'=>'web-01']), DomainException::class);
    $interface=$repo->saveInterface(['host_id'=>$host['id'],'network_id'=>$subnet['id'],'name'=>'eth0','ips'=>"10.20.0.2\n10.20.0.3"],(int)$placed['interface']['id']);equal(['10.20.0.2','10.20.0.3'],$interface['ips']);
    throws(fn() => $repo->saveInterface(['host_id'=>$host['id'],'network_id'=>$subnet['id'],'name'=>'eth0','ip'=>'10.20.0.2','dns_name'=>'extern.example.net'], (int)$interface['id']), DomainException::class);
    throws(fn() => $repo->saveReservation(['network_id'=>$subnet['id'],'ip'=>'10.20.0.2','label'=>'Doppelt']), DomainException::class);
    $repo->saveReservation(['network_id'=>$subnet['id'],'ip'=>'10.20.0.20','label'=>'VIP']);
    $current = $planner->network((int)$subnet['id']);
    equal(2, (int)$current['assigned_count']); equal(1, (int)$current['reserved_count']); equal(122, (int)$current['free_count']);
    $automatic=$repo->saveInterface(['host_id'=>$absoluteHost['id'],'network_id'=>$subnet['id'],'name'=>'eth0','ips'=>'']);
    equal('10.20.0.4',$automatic['ip']);
    $interfaceEdge=array_values(array_filter($repo->topology()['edges'],fn($edge)=>$edge['type']==='assigned'&&(int)$edge['target']===(int)$host['id']))[0];
    equal('eth0 · 10.20.0.2, 10.20.0.3',$interfaceEdge['label']);
    $repo->saveSite(['name'=>'Wien','domain_name'=>'vienna.example.at'], (int)$site['id']);
    $renamed = array_values(array_filter($repo->hosts(), fn($item) => (int)$item['id'] === (int)$host['id']))[0];
    equal('web-01.vienna.example.at', $renamed['fqdn']);
    equal(['extern.example.net','www.vienna.example.at'], array_column($renamed['aliases'], 'fqdn'));
    $absoluteRenamed = array_values(array_filter($repo->hosts(), fn($item) => (int)$item['id'] === (int)$absoluteHost['id']))[0];
    equal('office.autismus-asperger.at', $absoluteRenamed['fqdn']);
    $otherHost=$repo->saveHost(['site_id'=>$other['id'],'name'=>'Webserver Graz','hostname'=>'web-01']);
    throws(fn()=>$repo->saveSite(['name'=>'Graz','domain_name'=>'vienna.example.at'],(int)$other['id']),DomainException::class);
    $otherCurrent=array_values(array_filter($repo->hosts(),fn($item)=>(int)$item['id']===(int)$otherHost['id']))[0];
    equal('web-01.graz.example.at',$otherCurrent['fqdn']);
    $split=$planner->applySplit((int)$subnet['id'],2);equal(2,count($split['children']));
    $moved=$repo->interfaces((int)$host['id']);equal((int)$split['children'][0]['id'],(int)$moved[0]['network_id']);equal('10.20.0.2',$moved[0]['ip']);
    @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm');
});

test('Offener Adressraum wächst ausgerichtet und warnt an Privatgrenzen',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-test-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$site=$repo->saveSite(['name'=>'Edge','domain_name'=>'edge.example.at']);$space=$planner->saveSpace(['name'=>'Open','start_ip'=>'172.31.255.0']);
    $first=$planner->recommendNetwork(['address_space_id'=>$space['id'],'site_id'=>$site['id'],'name'=>'Gross','node_type'=>'leaf','requested_hosts'=>300]);
    equal('172.32.0.0/23',$first['cidr']);equal(true,$first['warning']!=='');
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Objektnamen sind innerhalb einer Site eindeutig und zwischen Sites wiederverwendbar',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-site-names-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Gemeinsam','start_ip'=>'10.35.0.0']);$home=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Home','domain_name'=>'home.example']);$road=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Road','domain_name'=>'road.example']);
    $apply=function(array$operation)use($replanner,$space){$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>$operation]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};
    $homeInfra=$apply(['action'=>'create','node_type'=>'container','name'=>'Infra','site_id'=>$home['id']]);$roadInfra=$apply(['action'=>'create','node_type'=>'container','name'=>'Infra','site_id'=>$road['id']]);
    equal(true,(int)$homeInfra['created_id']!==(int)$roadInfra['created_id']);
    $other=$apply(['action'=>'create','node_type'=>'leaf','name'=>'Andere','site_id'=>$road['id'],'requested_hosts'=>2]);
    throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$other['created_id'],'site_id'=>$road['id'],'name'=>'infra']]),DomainException::class);
    $homeHost=$repo->saveHost(['site_id'=>$home['id'],'name'=>'Server','hostname'=>'server']);$roadHost=$repo->saveHost(['site_id'=>$road['id'],'name'=>'Server','hostname'=>'server']);equal('Home',$homeHost['site_name']);equal('Road',$roadHost['site_name']);
    throws(fn()=>$repo->saveHost(['site_id'=>$road['id'],'name'=>'server','hostname'=>'server-2']),DomainException::class);
    $options=array_column($repo->objectOptions(),null,'id');equal('Home',$options[$homeInfra['created_id']]['site_name']);equal('Road',$options[$roadHost['id']]['site_name']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Site-Kennzahlen und Netz-Metadaten verwenden die aktive Planungsstruktur', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-test-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);
    $site=$repo->saveSite(['name'=>'Wien','domain_name'=>'wien.example.at']);$space=$planner->saveSpace(['name'=>'Intern','start_ip'=>'10.40.0.0']);
    $proposal=$planner->recommendNetwork(['address_space_id'=>$space['id'],'site_id'=>$site['id'],'name'=>'Server','node_type'=>'leaf','requested_hosts'=>20,'prefix'=>27]);
    $network=$planner->applyNetwork(['input'=>$proposal['input'],'expected_cidr'=>$proposal['cidr'],'revision'=>$proposal['revision']]);
    $planner->applyHost(['network_id'=>$network['id'],'host'=>['name'=>'App','hostname'=>'app-01']]);
    $repo->saveReservation(['network_id'=>$network['id'],'ip'=>'10.40.0.20','label'=>'VIP']);
    $summary=$repo->sites()[0];equal(1,(int)$summary['subnet_count']);equal(1,(int)$summary['host_count']);equal(29,(int)$summary['usable_addresses']);equal(2,(int)$summary['occupied_addresses']);equal('10.40.0.0/27',$summary['site_cidr']);equal('10.40.0.0 – 10.40.0.31',$summary['site_range']);equal(['10.40.0.0/27'],$summary['site_cidrs']);
    $topologySite=array_values(array_filter($repo->topology()['nodes'],fn($node)=>$node['kind']==='site'&&(int)$node['id']===(int)$site['id']))[0];equal('10.40.0.0/27',$topologySite['site_cidr']);equal(27,(int)$topologySite['free_addresses']);
    $updated=$planner->saveNetwork(['name'=>'Applikationen','site_id'=>$site['id'],'requested_hosts'=>25,'reserve_percent'=>30,'vlan_id'=>120,'vrf_name'=>'intern','description'=>'Produktiv'],(int)$network['id']);
    equal('Applikationen',$updated['name']);equal('10.40.0.0/27',$updated['cidr']);equal(120,(int)$updated['vlan_id']);
    $topologyNetwork=array_values(array_filter($repo->topology()['nodes'],fn($node)=>(int)$node['id']===(int)$network['id']))[0];equal(120,$topologyNetwork['vlan_id']);equal('10.40.0.0/27 · VLAN 120',$topologyNetwork['subtitle']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Dynamischer Replan wächst, respektiert Reihenfolge und kann zurückgerollt werden', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-dynamic-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $site=$repo->saveSite(['name'=>'Dynamic','domain_name'=>'dynamic.example']);$space=$planner->saveSpace(['name'=>'Main','start_ip'=>'172.20.0.0','routing_domain'=>'default']);
    $first=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Small','site_id'=>$site['id'],'requested_hosts'=>10,'reserve_percent'=>25]]);equal('172.20.0.0/28',$first['effective_cidr']);$createdA=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$first['operation'],'revision'=>$first['revision'],'plan_hash'=>$first['plan_hash']]);
    $second=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Large','site_id'=>$site['id'],'requested_hosts'=>100,'reserve_percent'=>25]]);equal('172.20.0.0/24',$second['effective_cidr']);$createdB=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$second['operation'],'revision'=>$second['revision'],'plan_hash'=>$second['plan_hash']]);
    $placed=$planner->applyHost(['network_id'=>$createdA['created_id'],'host'=>['name'=>'Dynamic Host','hostname'=>'dyn-01']]);$repo->saveInterface(['host_id'=>$placed['host']['id'],'network_id'=>$createdA['created_id'],'name'=>'eth0','ips'=>['172.20.0.2','172.20.0.3']],(int)$placed['interface']['id']);
    $reorder=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'reorder','ordered_ids'=>[$createdB['created_id'],$createdA['created_id']]]]);equal(2,count($reorder['address_changes']));equal(['172.20.0.130','172.20.0.131'],array_column($reorder['address_changes'],'new'));$applied=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$reorder['operation'],'revision'=>$reorder['revision'],'plan_hash'=>$reorder['plan_hash']]);
    $rollback=$replanner->rollbackPreview((int)$applied['run_id']);equal(['172.20.0.2','172.20.0.3'],array_column($rollback['address_changes'],'new'));$replanner->applyRollback(['run_id'=>$applied['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);equal(['172.20.0.2','172.20.0.3'],$repo->interfaces((int)$placed['host']['id'])[0]['ips']);
    $sameVrf=$planner->saveSpace(['name'=>'Collision','start_ip'=>'172.20.0.64','routing_domain'=>'default']);$collisionSite=$repo->saveSite(['address_space_id'=>$sameVrf['id'],'name'=>'Collision Site','domain_name'=>'collision.example']);throws(fn()=>$replanner->preview(['address_space_id'=>$sameVrf['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Collision','site_id'=>$collisionSite['id'],'requested_hosts'=>10]]),DomainException::class);
    throws(fn()=>$planner->saveSpace(['name'=>'Other','start_ip'=>'172.20.0.0']),\PDOException::class);
    $edge=$planner->saveSpace(['name'=>'Private Edge','start_ip'=>'192.168.255.0']);$edgeSite=$repo->saveSite(['address_space_id'=>$edge['id'],'name'=>'Edge Site','domain_name'=>'edge.example']);$edgePreview=$replanner->preview(['address_space_id'=>$edge['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Growing','site_id'=>$edgeSite['id'],'requested_hosts'=>300]]);equal(true,$edgePreview['requires_boundary_confirmation']);throws(fn()=>$replanner->apply(['address_space_id'=>$edge['id'],'operation'=>$edgePreview['operation'],'revision'=>$edgePreview['revision'],'plan_hash'=>$edgePreview['plan_hash']]),DomainException::class);
    equal(4,count($replanner->history((int)$space['id'])));
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Dynamische Subnetze akzeptieren einen optionalen festen Präfix', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-prefix-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Prefixraum','start_ip'=>'10.60.0.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Prefix Site','domain_name'=>'prefix.example']);
    $fixed=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Fest','site_id'=>$site['id'],'prefix'=>'/24','requested_hosts'=>10]]);equal('10.60.0.0/24',$fixed['effective_cidr']);equal(24,$fixed['plan']['nodes'][0]['manual_prefix']);
    $created=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$fixed['operation'],'revision'=>$fixed['revision'],'plan_hash'=>$fixed['plan_hash']]);$network=$planner->network((int)$created['created_id']);equal('10.60.0.0/24',$network['cidr']);equal(24,(int)$network['manual_prefix']);
    $dynamic=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$created['created_id'],'name'=>'Fest','site_id'=>$site['id'],'prefix'=>'','requested_hosts'=>10,'reserve_percent'=>25]]);equal('10.60.0.0/28',$dynamic['effective_cidr']);$changed=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$dynamic['operation'],'revision'=>$dynamic['revision'],'plan_hash'=>$dynamic['plan_hash']]);equal(null,$planner->network((int)$created['created_id'])['manual_prefix']);
    $rollback=$replanner->rollbackPreview((int)$changed['run_id']);$replanner->applyRollback(['run_id'=>$changed['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);$restored=$planner->network((int)$created['created_id']);equal('10.60.0.0/24',$restored['cidr']);equal(24,(int)$restored['manual_prefix']);
    throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$created['created_id'],'name'=>'Fest','site_id'=>$site['id'],'prefix'=>'/29','requested_hosts'=>20]]),DomainException::class);
    throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$created['created_id'],'name'=>'Fest','site_id'=>$site['id'],'prefix'=>'abc']]),InvalidArgumentException::class);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Subnetze können optional an einer festen Start-IP platziert werden', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-start-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Startraum','start_ip'=>'10.70.0.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Start Site','domain_name'=>'start.example']);
    $fixed=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Fest platziert','site_id'=>$site['id'],'prefix'=>'/26','start_ip'=>'10.70.1.64','requested_hosts'=>10]]);equal('10.70.1.64/26',$fixed['plan']['nodes'][0]['cidr']);equal(IpMath::toInt('10.70.1.64'),$fixed['plan']['nodes'][0]['manual_start_int']);equal('10.70.0.0/23',$fixed['effective_cidr']);
    $created=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$fixed['operation'],'revision'=>$fixed['revision'],'plan_hash'=>$fixed['plan_hash']]);$network=$planner->network((int)$created['created_id']);equal('10.70.1.64/26',$network['cidr']);equal('10.70.1.64',$network['manual_start_ip']);
    $automatic=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$created['created_id'],'name'=>'Fest platziert','site_id'=>$site['id'],'prefix'=>'/26','start_ip'=>'','requested_hosts'=>10,'reserve_percent'=>25]]);equal('10.70.0.0/26',$automatic['plan']['nodes'][0]['cidr']);$changed=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$automatic['operation'],'revision'=>$automatic['revision'],'plan_hash'=>$automatic['plan_hash']]);equal('',$planner->network((int)$created['created_id'])['manual_start_ip']);
    $rollback=$replanner->rollbackPreview((int)$changed['run_id']);$replanner->applyRollback(['run_id'=>$changed['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);equal('10.70.1.64',$planner->network((int)$created['created_id'])['manual_start_ip']);
    throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$created['created_id'],'name'=>'Fest platziert','site_id'=>$site['id'],'prefix'=>'/26','start_ip'=>'10.70.1.65','requested_hosts'=>10,'reserve_percent'=>25]]),DomainException::class);
    throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Davor','site_id'=>$site['id'],'prefix'=>'/26','start_ip'=>'10.70.0.0','requested_hosts'=>10]]),DomainException::class);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Belegte Adressräume lassen sich mit festen Ankern atomar verschieben und zurückrollen', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-relocate-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'AutAsp','start_ip'=>'172.21.10.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Aut','domain_name'=>'aut.example']);
    $apply=function(array$operation)use($replanner,$space){$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>$operation]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};
    $lan=(int)$apply(['action'=>'create','node_type'=>'leaf','name'=>'LAN klein','site_id'=>$site['id'],'requested_hosts'=>0])['created_id'];$anchored=(int)$apply(['action'=>'create','node_type'=>'leaf','name'=>'LAN L3','site_id'=>$site['id'],'prefix'=>'/24','start_ip'=>'172.21.11.0','requested_hosts'=>10])['created_id'];
    $gatewayRouter=$repo->saveRouter(['site_id'=>$site['id'],'name'=>'Gateway','hostname'=>'gateway']);$gateway=$repo->saveInterface(['host_id'=>$gatewayRouter['id'],'network_id'=>$anchored,'name'=>'lan0']);$router=$repo->saveRouter(['site_id'=>$site['id'],'name'=>'Router','hostname'=>'router']);$egress=$repo->saveInterface(['host_id'=>$router['id'],'network_id'=>$anchored,'name'=>'lan0','ip'=>'172.21.11.2']);$route=$repo->saveStaticRoute(['router_id'=>$router['id'],'destination_cidr'=>'10.0.0.0/8','egress_interface_id'=>$egress['id'],'next_hop'=>'172.21.11.1','metric'=>10]);
    throws(fn()=>$planner->saveSpace(['name'=>'AutAsp','start_ip'=>'172.21.12.0'],(int)$space['id']),DomainException::class);
    $request=['address_space_id'=>$space['id'],'operation'=>['action'=>'relocate_space','new_start_ip'=>'172.21.12.0','space_name'=>'AutAsp','reserve_percent'=>25,'description'=>'']];$preview=$replanner->preview($request);equal(true,$preview['requires_explicit_confirmation']);equal('172.21.12.0/23',$preview['effective_cidr']);$planned=array_column($preview['plan']['nodes'],null,'id');equal('172.21.12.0/29',$planned[$lan]['cidr']);equal('172.21.13.0/24',$planned[$anchored]['cidr']);equal(IpMath::toInt('172.21.13.0'),$planned[$anchored]['manual_start_int']);equal(true,count(array_filter($preview['address_changes'],fn($change)=>$change['old']==='172.21.11.1'&&$change['new']==='172.21.13.1'))===1);
    throws(fn()=>$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]),DomainException::class);$moved=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash'],'confirm_relocation'=>true]);$movedSpace=array_values(array_filter($planner->spaces(),fn($row)=>(int)$row['id']===(int)$space['id']))[0];equal('172.21.12.0',$movedSpace['start_ip']);equal('172.21.13.0/24',$planner->network($anchored)['cidr']);equal('172.21.13.0',$planner->network($anchored)['manual_start_ip']);$interfaces=array_column($repo->interfaces(),null,'id');equal('172.21.13.1',$interfaces[$gateway['id']]['ip']);equal('172.21.13.2',$interfaces[$egress['id']]['ip']);$routes=array_column($repo->routerRoutes((int)$router['id']),null,'id');equal('172.21.13.1',$routes[$route['id']]['next_hop']);
    $rollback=$replanner->rollbackPreview((int)$moved['run_id']);$replanner->applyRollback(['run_id'=>$moved['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);$restoredSpace=array_values(array_filter($planner->spaces(),fn($row)=>(int)$row['id']===(int)$space['id']))[0];equal('172.21.10.0',$restoredSpace['start_ip']);equal('172.21.11.0/24',$planner->network($anchored)['cidr']);$routes=array_column($repo->routerRoutes((int)$router['id']),null,'id');equal('172.21.11.1',$routes[$route['id']]['next_hop']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Netze lassen sich atomar gruppieren, verschachteln und zurückwandeln', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-tree-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);$space=$planner->saveSpace(['name'=>'Baum','start_ip'=>'10.70.0.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Baum Site','domain_name'=>'baum.example']);
    $apply=function(array$operation)use($replanner,$space){$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>$operation]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};
    $make=function(string$name)use($apply,$site){return(int)$apply(['action'=>'create','node_type'=>'leaf','name'=>$name,'site_id'=>$site['id'],'requested_hosts'=>5])['created_id'];};$a=$make('A');$b=$make('B');$c=$make('C');$apply(['action'=>'reparent','ids'=>[$c],'parent_id'=>null,'anchor_id'=>$a,'position'=>'before']);$roots=array_values(array_filter($planner->networks(),fn($network)=>$network['parent_id']===null));equal([$c,$a,$b],array_map(fn($network)=>(int)$network['id'],$roots));
    $wrapped=$apply(['action'=>'wrap','ids'=>[$a,$b],'wrapper_role'=>'aggregate','name'=>'AB-Supernetz','prefix'=>'/24']);$aggregate=(int)$wrapped['created_id'];$nets=array_column($planner->networks(),null,'id');equal('aggregate',$nets[$aggregate]['node_role']);equal(24,(int)$nets[$aggregate]['manual_prefix']);equal($aggregate,(int)$nets[$a]['parent_id']);equal($aggregate,(int)$nets[$b]['parent_id']);
    $moved=$apply(['action'=>'reparent','ids'=>[$c],'parent_id'=>$aggregate]);$nets=array_column($planner->networks(),null,'id');equal($aggregate,(int)$nets[$c]['parent_id']);equal('10.70.0.0/24',$nets[$aggregate]['cidr']);$topologyNode=array_values(array_filter($repo->topology()['nodes'],fn($node)=>(int)$node['id']===$aggregate))[0];equal('aggregate',$topologyNode['node_role']);throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update','id'=>$aggregate,'name'=>'AB-Supernetz','site_id'=>$site['id'],'prefix'=>'/29']]),DomainException::class);$rollback=$replanner->rollbackPreview((int)$moved['run_id']);$replanner->applyRollback(['run_id'=>$moved['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);equal(null,$planner->network($c)['parent_id']);$apply(['action'=>'reparent','ids'=>[$c],'parent_id'=>$aggregate]);
    $apply(['action'=>'reparent','ids'=>[$a,$b,$c],'parent_id'=>null]);$apply(['action'=>'convert_to_leaf','id'=>$aggregate]);$nets=array_column($planner->networks(),null,'id');equal('subnet',$nets[$aggregate]['node_role']);equal(24,(int)$nets[$aggregate]['prefix']);
    $placed=$planner->applyHost(['network_id'=>$c,'host'=>['name'=>'Belegt','hostname'=>'belegt']]);throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'reparent','ids'=>[$a],'parent_id'=>$c]]),DomainException::class);
    $group=$apply(['action'=>'wrap','ids'=>[$a,$b],'wrapper_role'=>'group','name'=>'AB-Gruppe'])['created_id'];$topologyNodes=array_column($repo->topology()['nodes'],null,'id');equal('group',$topologyNodes[$group]['node_role']);equal((int)$group,(int)$topologyNodes[$a]['parent_id']);equal((int)$group,(int)$topologyNodes[$b]['parent_id']);throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'reparent','ids'=>[$group],'parent_id'=>$a]]),DomainException::class);
    $asAggregate=$apply(['action'=>'convert_container_role','id'=>$group,'target_role'=>'aggregate','prefix'=>'/24']);$converted=$planner->network((int)$group);equal('aggregate',$converted['node_role']);equal(24,(int)$converted['manual_prefix']);
    $conversionRollback=$replanner->rollbackPreview((int)$asAggregate['run_id']);$replanner->applyRollback(['run_id'=>$asAggregate['run_id'],'revision'=>$conversionRollback['revision'],'plan_hash'=>$conversionRollback['plan_hash']]);equal('group',$planner->network((int)$group)['node_role']);
    $apply(['action'=>'convert_container_role','id'=>$group,'target_role'=>'aggregate','prefix'=>'']);$apply(['action'=>'convert_container_role','id'=>$group,'target_role'=>'group']);$convertedBack=$planner->network((int)$group);equal('group',$convertedBack['node_role']);equal(null,$convertedBack['manual_prefix']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Gemeinsames L3-Subnetz nutzt statische und DHCP-Adresspools', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-l3-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);$space=$planner->saveSpace(['name'=>'L3','start_ip'=>'172.20.1.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'L3 Site','domain_name'=>'l3.example']);
    $apply=function(array$operation)use($replanner,$space){$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>$operation]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};$make=function($name)use($apply,$site){return(int)$apply(['action'=>'create','node_type'=>'leaf','name'=>$name,'site_id'=>$site['id'],'prefix'=>'/26'])['created_id'];};$static=$make('Statisch');$dynamic=$make('Dynamisch');$parent=(int)$apply(['action'=>'wrap','ids'=>[$static,$dynamic],'wrapper_role'=>'aggregate','name'=>'Clientnetz','prefix'=>'/25'])['created_id'];
    $router=$repo->saveRouter(['site_id'=>$site['id'],'name'=>'R-L3','hostname'=>'r-l3']);$gateway=$repo->saveInterface(['host_id'=>$router['id'],'network_id'=>$static,'name'=>'lan0']);equal('172.20.1.1',$gateway['ip']);$host=$repo->saveHost(['site_id'=>$site['id'],'name'=>'Altgerät','hostname'=>'alt']);$old=$repo->saveInterface(['host_id'=>$host['id'],'network_id'=>$static,'name'=>'eth0','ip'=>'172.20.1.2']);
    $activated=$apply(['action'=>'activate_l3','id'=>$parent,'pool_type'=>'static_pool','gateway'=>'172.20.1.1']);$networks=array_column($planner->networks(),null,'id');equal('l3_subnet',$networks[$parent]['node_role']);equal('static_pool',$networks[$static]['node_role']);equal(0,(int)$networks[$static]['pool_range_fixed']);equal('172.20.1.2',$networks[$static]['range_start']);equal('172.20.1.63',$networks[$static]['range_end']);equal('172.20.1.64',$networks[$dynamic]['range_start']);equal('172.20.1.126',$networks[$dynamic]['range_end']);$interfaces=array_column($repo->interfaces(),null,'id');equal($parent,(int)$interfaces[$old['id']]['network_id']);equal($static,(int)$interfaces[$old['id']]['pool_id']);equal($parent,(int)$interfaces[$gateway['id']]['network_id']);equal(null,$interfaces[$gateway['id']]['pool_id']);$siteStats=$repo->sites()[0];equal(1,(int)$siteStats['subnet_count']);equal(2,(int)$siteStats['pool_count']);$dashboard=$repo->dashboard();equal(1,$dashboard['subnets']);equal(2,$dashboard['pools']);$topologyRoles=array_column(array_filter($repo->topology()['nodes'],fn($n)=>isset($n['node_role'])),'node_role','id');equal('l3_subnet',$topologyRoles[$parent]);equal('static_pool',$topologyRoles[$static]);throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'convert_container_role','id'=>$parent,'target_role'=>'group']]),DomainException::class);
    $rollback=$replanner->rollbackPreview((int)$activated['run_id']);$replanner->applyRollback(['run_id'=>$activated['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);$rolledNetworks=array_column($planner->networks(),null,'id');equal('aggregate',$rolledNetworks[$parent]['node_role']);equal('subnet',$rolledNetworks[$static]['node_role']);$rolledInterfaces=array_column($repo->interfaces(),null,'id');equal($static,(int)$rolledInterfaces[$gateway['id']]['network_id']);equal(1,(int)$rolledInterfaces[$gateway['id']]['is_gateway']);$apply(['action'=>'activate_l3','id'=>$parent,'pool_type'=>'static_pool','gateway'=>'172.20.1.1']);$automaticHost=$repo->saveHost(['site_id'=>$site['id'],'name'=>'Automatisch','hostname'=>'auto']);$automaticInterface=$repo->saveInterface(['host_id'=>$automaticHost['id'],'network_id'=>$parent,'name'=>'eth0','ips'=>'']);equal('172.20.1.3',$automaticInterface['ip']);equal($static,(int)$automaticInterface['pool_id']);
    $apply(['action'=>'update_pool','id'=>$dynamic,'pool_type'=>'dhcp_pool','prefix'=>'','range_start'=>'','range_end'=>'']);$scalable=$planner->network($dynamic);equal(0,(int)$scalable['pool_range_fixed']);equal(null,$scalable['manual_prefix']);equal('172.20.1.64',$scalable['range_start']);equal('172.20.1.71',$scalable['range_end']);throws(fn()=>$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update_pool','id'=>$dynamic,'pool_type'=>'dhcp_pool','range_start'=>'172.20.1.64','range_end'=>'']]),InvalidArgumentException::class);
    $apply(['action'=>'update_pool','id'=>$dynamic,'pool_type'=>'dhcp_pool','prefix'=>'/26','range_start'=>'172.20.1.64','range_end'=>'172.20.1.126']);$fixedPool=$planner->network($dynamic);equal(1,(int)$fixedPool['pool_range_fixed']);equal('dhcp_pool',$fixedPool['node_role']);$client=$repo->saveHost(['site_id'=>$site['id'],'name'=>'DHCP Client','hostname'=>'dhcp']);throws(fn()=>$repo->saveInterface(['host_id'=>$client['id'],'network_id'=>$parent,'name'=>'eth0','ip'=>'172.20.1.64']),DomainException::class);$dhcp=$repo->saveInterface(['host_id'=>$client['id'],'network_id'=>$parent,'name'=>'eth0','ip'=>'172.20.1.64','confirm_dhcp_overlap'=>1]);equal($dynamic,(int)$dhcp['pool_id']);$poolEdges=$repo->topology()['edges'];equal(true,count(array_filter($poolEdges,fn($edge)=>(int)$edge['source']===$static&&(int)$edge['target']===(int)$host['id']&&$edge['type']==='assigned'))===1);equal(true,count(array_filter($poolEdges,fn($edge)=>(int)$edge['source']===$dynamic&&(int)$edge['target']===(int)$client['id']&&$edge['type']==='assigned'))===1);equal(0,count(array_filter($poolEdges,fn($edge)=>(int)$edge['source']===$parent&&(int)$edge['target']===(int)$client['id']&&$edge['type']==='assigned')));$reservation=$repo->saveReservation(['network_id'=>$dynamic,'ip'=>'172.20.1.65','label'=>'Ausnahme','reservation_type'=>'dhcp_exclusion']);equal('dhcp_exclusion',$reservation['reservation_type']);
    equal(true,count(array_filter($planner->hostCandidates((int)$site['id']),fn($candidate)=>(int)($candidate['pool_id']??0)===$static))>0);equal(0,count(array_filter($planner->hostCandidates((int)$site['id']),fn($candidate)=>(int)($candidate['pool_id']??0)===$dynamic)));
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Globale VPN- und Docker-Netze bleiben fest und verbinden sitefremde Geräte',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-virtual-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);$space=$planner->saveSpace(['name'=>'Dynamisch','start_ip'=>'10.44.0.0']);$a=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Wien','domain_name'=>'wien.vpn.example']);$b=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Graz','domain_name'=>'graz.vpn.example']);
    $initial=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'LAN','site_id'=>$a['id'],'prefix'=>'/29']]);$r1=$repo->saveRouter(['site_id'=>$a['id'],'name'=>'VPN-R1','hostname'=>'vpn-r1']);$r2=$repo->saveRouter(['site_id'=>$b['id'],'name'=>'VPN-R2','hostname'=>'vpn-r2']);$host=$repo->saveHost(['site_id'=>$a['id'],'name'=>'Dockerhost','hostname'=>'docker']);
    $vpn=$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'WireGuard Backbone','cidr'=>'100.64.0.0/29']);equal('vpn',$vpn['network_type']);$v1=$repo->saveVpnInterface(['virtual_network_id'=>$vpn['id'],'router_id'=>$r1['id'],'name'=>'wg0','ips'=>['100.64.0.1','100.64.0.3']]);$repo->saveVpnInterface(['virtual_network_id'=>$vpn['id'],'router_id'=>$r2['id'],'name'=>'wg0','ip'=>'100.64.0.2']);equal(['100.64.0.1','100.64.0.3'],$v1['ips']);$vpn2=$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'WireGuard Partner','cidr'=>'100.64.0.8/29']);$shared=$repo->saveVpnInterface(['virtual_network_id'=>$vpn2['id'],'router_id'=>$r1['id'],'name'=>'wg0','ip'=>'100.64.0.9']);equal('wg0',$shared['name']);equal(true,count(array_filter($repo->routerRoutes((int)$r1['id']),fn($route)=>$route['id']==='vpn-connected-'.$v1['id']))===1);throws(fn()=>$repo->saveVpnInterface(['virtual_network_id'=>$vpn['id'],'router_id'=>$r2['id'],'name'=>'wg1','ip'=>'100.64.0.3']),DomainException::class);throws(fn()=>$repo->deleteVirtualNetwork((int)$vpn['id']),DomainException::class);
    $dockerA=$repo->saveVirtualNetwork(['network_type'=>'docker_bridge','name'=>'docker0-a','cidr'=>'172.17.0.0/16','owner_host_id'=>$host['id']]);equal('172.17.0.1',$dockerA['gateway']);$dockerB=$repo->saveVirtualNetwork(['network_type'=>'docker_bridge','name'=>'docker0-b','cidr'=>'172.17.0.0/16','owner_host_id'=>$r2['id']]);equal('bridge',$dockerB['driver']);throws(fn()=>$repo->saveVirtualNetwork(['network_type'=>'docker_bridge','name'=>'docker-overlap','cidr'=>'172.17.1.0/24','owner_host_id'=>$host['id']]),DomainException::class);throws(fn()=>$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'VPN-Kollision','cidr'=>'172.17.0.0/24']),DomainException::class);throws(fn()=>$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'LAN-Kollision','cidr'=>'10.44.0.0/29']),DomainException::class);
    $future=$repo->saveVirtualNetwork(['network_type'=>'docker_bridge','name'=>'future-docker','cidr'=>'10.44.0.16/29','owner_host_id'=>$r2['id']]);$growth=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Wachstum','site_id'=>$a['id'],'prefix'=>'/28']]);$growthNode=array_values(array_filter($growth['plan']['nodes'],fn($node)=>$node['name']==='Wachstum'))[0];equal('10.44.0.32/28',$growthNode['cidr']);$topology=$repo->topology((int)$a['id']);equal(5,count(array_filter($topology['nodes'],fn($node)=>$node['kind']==='virtual_network')));equal(1,count(array_filter($topology['edges'],fn($edge)=>(int)$edge['source']===(int)$vpn['id'])));throws(fn()=>$repo->delete('hosts',(int)$host['id']),DomainException::class);equal(true,count(array_filter($repo->objectOptions(),fn($option)=>$option['kind']==='virtual_network'))===5);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('VPN-Netze reservieren Lücken im dynamischen Plan und verschieben Interface-IPs atomar',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-vpn-prefix-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'MoCu','start_ip'=>'172.20.1.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Home','domain_name'=>'home.example']);
    $apply=function(array$operation)use($replanner,$space){$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>$operation]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};
    $apply(['action'=>'create','node_type'=>'leaf','name'=>'Erster Bereich','site_id'=>$site['id'],'prefix'=>'/24']);$apply(['action'=>'create','node_type'=>'leaf','name'=>'Zweiter Bereich','site_id'=>$site['id'],'prefix'=>'/24']);
    equal('172.20.0.0/22',$replanner->summary((int)$space['id'])['effective_cidr']);
    $router=$repo->saveRouter(['site_id'=>$site['id'],'name'=>'Gateway','hostname'=>'gateway']);$vpn=$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'MoCu VPN','cidr'=>'172.20.200.0/24']);$interface=$repo->saveVpnInterface(['virtual_network_id'=>$vpn['id'],'router_id'=>$router['id'],'name'=>'wg0','ips'=>['172.20.200.1','172.20.200.2']]);
    $moved=$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'MoCu VPN','cidr'=>'172.20.0.0/24'],(int)$vpn['id']);equal('172.20.0.0/24',$moved['cidr']);$interfaces=array_values(array_filter($repo->vpnInterfaces(),fn($item)=>(int)$item['id']===(int)$interface['id']));equal(['172.20.0.1','172.20.0.2'],$interfaces[0]['ips']);
    equal('172.20.0.0/22',$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'none']])['effective_cidr']);
    $relocation=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'relocate_space','new_start_ip'=>'172.20.0.0','space_name'=>'MoCu','reserve_percent'=>25,'description'=>'']]);$planned=array_column($relocation['plan']['nodes'],null,'name');equal('172.20.1.0/24',$planned['Erster Bereich']['cidr']);equal('172.20.2.0/24',$planned['Zweiter Bereich']['cidr']);$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$relocation['operation'],'revision'=>$relocation['revision'],'plan_hash'=>$relocation['plan_hash'],'confirm_relocation'=>true]);equal('172.20.0.0',$planner->spaces()[0]['start_ip']);
    throws(fn()=>$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'Kollision','cidr'=>'172.20.1.0/24']),DomainException::class);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Ein VPN an beliebiger freier Position wird bei späterem Wachstum übersprungen',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-vpn-gap-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Mit VPN-Lücke','start_ip'=>'10.50.0.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Site','domain_name'=>'site.example']);$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'VPN-Mitte','cidr'=>'10.50.1.0/24']);
    $apply=function(string$name)use($replanner,$space,$site):void{$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>$name,'site_id'=>$site['id'],'prefix'=>'/24']]);$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};
    $apply('Vor VPN');$apply('Nach VPN');$planned=array_column($planner->networks((int)$space['id']),null,'name');equal('10.50.0.0/24',$planned['Vor VPN']['cidr']);equal('10.50.2.0/24',$planned['Nach VPN']['cidr']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Sites können zwischen kompakter IP-Range und aggregierbarem CIDR-Block wechseln',function():void{
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-site-mode-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Site-Modi','start_ip'=>'10.51.0.0']);$site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Home','domain_name'=>'home.example','allocation_mode'=>'range']);$repo->saveVirtualNetwork(['network_type'=>'vpn','name'=>'VPN','cidr'=>'10.51.0.0/24']);
    $applyCreate=function(string$name,string$prefix)use($replanner,$space,$site):void{$preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>$name,'site_id'=>$site['id'],'prefix'=>$prefix]]);$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);};$applyCreate('Infra','/27');$applyCreate('Clients','/24');
    $range=array_column($planner->networks((int)$space['id']),null,'name');equal('10.51.1.0/27',$range['Infra']['cidr']);equal('10.51.2.0/24',$range['Clients']['cidr']);
    $preview=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'update_site_allocation','site_id'=>$site['id'],'allocation_mode'=>'cidr']]);$cidrNodes=array_column($preview['plan']['nodes'],null,'name');equal('10.51.2.0/27',$cidrNodes['Infra']['cidr']);equal('10.51.3.0/24',$cidrNodes['Clients']['cidr']);$result=$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$preview['operation'],'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);$summary=$repo->sites()[0];equal('cidr',$summary['allocation_mode']);equal('10.51.2.0/23',$summary['site_cidr']);
    $rollback=$replanner->rollbackPreview((int)$result['run_id']);$replanner->applyRollback(['run_id'=>$result['run_id'],'revision'=>$rollback['revision'],'plan_hash'=>$rollback['plan_hash']]);$summary=$repo->sites()[0];equal('range',$summary['allocation_mode']);$restored=array_column($planner->networks((int)$space['id']),null,'name');equal('10.51.1.0/27',$restored['Infra']['cidr']);equal('10.51.2.0/24',$restored['Clients']['cidr']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('VLSM plant und übernimmt atomar', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ipdesigner-test-');
    $repo = new Repository(Database::connect($path));
    $site=$repo->saveSite(['name'=>'Lab','domain_name'=>'lab.example.at']);$block=$repo->saveBlock(['site_id'=>$site['id'],'name'=>'Labnetz','cidr'=>'172.16.0.0/24']);
    $requests=[['name'=>'Clients','hosts'=>100],['name'=>'Server','hosts'=>40],['name'=>'P2P','prefix'=>31]];
    $plan=$repo->planSubnets((int)$block['id'],$requests);
    equal('172.16.0.0/25',$plan['proposals'][0]['cidr']);equal('172.16.0.128/26',$plan['proposals'][1]['cidr']);
    $created=$repo->applySubnetPlan((int)$block['id'],$requests);equal(3,count($created));
    throws(fn()=>$repo->applySubnetPlan((int)$block['id'],[['name'=>'Zu gross','hosts'=>200]]),DomainException::class);
    equal(3,count($repo->subnets((int)$block['id'])));
    @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm');
});

test('Adressräume sind Topologieobjekte und Sites eindeutig zugeordnet', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-hierarchy-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $space=$planner->saveSpace(['name'=>'Global','start_ip'=>'10.80.0.0']);$other=$planner->saveSpace(['name'=>'Isoliert','start_ip'=>'10.90.0.0','routing_domain'=>'isoliert']);
    $site=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Wien','domain_name'=>'wien.example']);
    equal((int)$space['id'],(int)$repo->sites()[0]['address_space_id']);
    $objects=$repo->objectOptions();equal(2,count(array_filter($objects,fn($o)=>$o['kind']==='address_space')));
    $topology=$repo->topology();$spaceNode=array_values(array_filter($topology['nodes'],fn($n)=>$n['kind']==='address_space'&&(int)$n['object_id']===(int)$space['id']))[0];
    $siteNode=array_values(array_filter($topology['nodes'],fn($n)=>$n['kind']==='site'&&(int)$n['id']===(int)$site['id']))[0];equal((int)$site['id'],(int)$siteNode['site_id']);
    equal(1,count(array_filter($topology['edges'],fn($e)=>(int)$e['source']===(int)$spaceNode['id']&&(int)$e['target']===(int)$site['id'])));
    throws(fn()=>$replanner->preview(['address_space_id'=>$other['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'Falsch','site_id'=>$site['id']]]),InvalidArgumentException::class);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Belegte Site wird mit Quell- und Zielraum atomar verschoben', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-move-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);
    $source=$planner->saveSpace(['name'=>'Quelle','start_ip'=>'10.100.0.0','routing_domain'=>'source']);$target=$planner->saveSpace(['name'=>'Ziel','start_ip'=>'10.200.0.0','routing_domain'=>'target']);$site=$repo->saveSite(['address_space_id'=>$source['id'],'name'=>'Move','domain_name'=>'move.example']);
    $created=$replanner->apply(['address_space_id'=>$source['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>'LAN','site_id'=>$site['id'],'requested_hosts'=>20]]);$placed=$planner->applyHost(['network_id'=>$created['created_id'],'host'=>['name'=>'Mover','hostname'=>'mover']]);
    $request=['site_id'=>$site['id'],'target_address_space_id'=>$target['id'],'site'=>['name'=>'Move','domain_name'=>'move.example','location'=>'','description'=>'']];$preview=$replanner->previewSiteMove($request);equal(true,$preview['site_move']);
    $replanner->applySiteMove([...$request,'revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);$movedSite=array_values(array_filter($repo->sites(),fn($s)=>(int)$s['id']===(int)$site['id']))[0];equal((int)$target['id'],(int)$movedSite['address_space_id']);equal((int)$target['id'],(int)$planner->network((int)$created['created_id'])['address_space_id']);equal('10.200.0.2',$repo->interfaces((int)$placed['host']['id'])[0]['ip']);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

test('Router verbinden Sites, übernehmen Gateways und erhalten Routenvorschläge', function (): void {
    $path=tempnam(sys_get_temp_dir(),'ipdesigner-router-');$pdo=Database::connect($path);$repo=new Repository($pdo);$planner=new NetworkPlanner($pdo);$replanner=new DynamicReplanner($pdo);$space=$planner->saveSpace(['name'=>'Routerraum','start_ip'=>'10.210.0.0']);$a=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'A','domain_name'=>'a.example']);$b=$repo->saveSite(['address_space_id'=>$space['id'],'name'=>'B','domain_name'=>'b.example']);
    $create=function($name,$site,$hosts)use($replanner,$space){$p=$replanner->preview(['address_space_id'=>$space['id'],'operation'=>['action'=>'create','node_type'=>'leaf','name'=>$name,'site_id'=>$site['id'],'requested_hosts'=>$hosts]]);return$replanner->apply(['address_space_id'=>$space['id'],'operation'=>$p['operation'],'revision'=>$p['revision'],'plan_hash'=>$p['plan_hash']])['created_id'];};$transit=$create('Transit',$a,10);$lanB=$create('LAN-B',$b,20);$transitNet=$planner->network($transit);$lanBNet=$planner->network($lanB);
    $r1=$repo->saveRouter(['site_id'=>$a['id'],'name'=>'R1','hostname'=>'r1']);$r2=$repo->saveRouter(['site_id'=>$b['id'],'name'=>'R2','hostname'=>'r2']);$tp=IpMath::parseCidr($transitNet['cidr']);$bp=IpMath::parseCidr($lanBNet['cidr']);$r1t=$repo->saveInterface(['host_id'=>$r1['id'],'network_id'=>$transit,'name'=>'wan0','ip'=>IpMath::toIp($tp['network']+2)]);throws(fn()=>$repo->saveInterface(['host_id'=>$r2['id'],'network_id'=>$transit,'name'=>'wan0','ip'=>IpMath::toIp($tp['network']+3)]),DomainException::class);$r2t=$repo->saveInterface(['host_id'=>$r2['id'],'network_id'=>$transit,'name'=>'wan0','ip'=>IpMath::toIp($tp['network']+3),'allow_cross_site'=>1]);$gateway=$repo->saveInterface(['host_id'=>$r2['id'],'network_id'=>$lanB,'name'=>'lan0']);equal(IpMath::toIp($bp['network']+1),$gateway['ip']);equal(1,(int)$gateway['is_gateway']);
    $suggestions=$repo->routeSuggestions((int)$r1['id']);equal($lanBNet['cidr'],$suggestions[0]['destination_cidr']);$route=$repo->saveStaticRoute($suggestions[0]);equal(IpMath::toIp($tp['network']+3),$route['next_hop']);equal(1,count(array_filter($repo->routerRoutes((int)$r1['id']),fn($r)=>$r['route_type']==='static')));
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
});

$failed = 0;
foreach ($tests as $name => $callback) {
    try { $callback(); fwrite(STDOUT, "✓ $name\n"); }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "✗ $name\n  {$error->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("\n%d Tests, %d Fehler\n", count($tests), $failed));
exit($failed === 0 ? 0 : 1);
