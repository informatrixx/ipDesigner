<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/src/bootstrap.php';

function fixtureNetwork(PDO $db, int $space, int $site, string $name, string $cidr, array $extra = []): int
{
    $parsed = IpDesigner\IpMath::parseCidr($cidr);
    $db->prepare('INSERT INTO entities(kind) VALUES (?)')->execute([$extra['entity_kind'] ?? 'subnet']);
    unset($extra['entity_kind']);
    $id = (int)$db->lastInsertId();
    $row = array_replace(['id'=>$id,'address_space_id'=>$space,'site_id'=>$site,'name'=>$name,
        'cidr'=>$cidr,'network_int'=>$parsed['network'],'broadcast_int'=>$parsed['broadcast'],
        'prefix'=>$parsed['prefix'],'node_type'=>'leaf','gateway_int'=>$parsed['first_usable']], $extra);
    $db->prepare('INSERT INTO network_nodes('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    return $id;
}

function networkCheckFixture(): array
{
    $db = IpDesigner\Database::connect(':memory:');
    IpDesigner\Database::migrate($db);
    $repo = new IpDesigner\Repository($db);
    $planner = new IpDesigner\NetworkPlanner($db);
    $space = $planner->saveSpace(['name'=>'Testplan','start_ip'=>'10.55.0.0']);
    $site = $repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Testsite','domain_name'=>'test.example']);
    $otherSite = $repo->saveSite(['address_space_id'=>$space['id'],'name'=>'Andere Site','domain_name'=>'other.example']);
    $network = fixtureNetwork($db,(int)$space['id'],(int)$site['id'],'LAN','10.55.0.0/29');
    $host = $repo->saveHost(['site_id'=>$site['id'],'name'=>'Server','hostname'=>'server','status'=>'active']);
    $interface = $repo->saveInterface(['host_id'=>$host['id'],'network_id'=>$network,'name'=>'eth0','ip'=>'10.55.0.2']);
    $router = $repo->saveRouter(['site_id'=>$site['id'],'name'=>'Gateway','hostname'=>'gateway']);
    $gateway = $repo->saveInterface(['host_id'=>$router['id'],'network_id'=>$network,'name'=>'lan0']);
    return compact('db','repo','planner','space','site','otherSite','network','host','interface','router','gateway');
}
