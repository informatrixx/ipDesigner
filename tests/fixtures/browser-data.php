<?php
declare(strict_types=1);
require __DIR__.'/network-check.php';
$f = networkCheckFixture(); $repo = $f['repo']; $planner = $f['planner'];
foreach (['10.55.0.2','10.55.0.3','10.55.0.4','10.55.0.5'] as $ip) {
    $f['db']->prepare('INSERT INTO network_reservations(network_id,ip_int,label) VALUES (?,?,?)')->execute([$f['network'],IpDesigner\IpMath::toInt($ip),'Testreservierung']);
}
$data = ['dashboard'=>$repo->dashboard(),'sites'=>$repo->sites(),'blocks'=>$repo->blocks(),'subnets'=>$repo->subnets(),
    'hosts'=>$repo->hosts(),'routers'=>$repo->routers(),'interfaces'=>$repo->interfaces(),'virtual-networks'=>$repo->virtualNetworks(),
    'vpn-interfaces'=>$repo->vpnInterfaces(),'services'=>$repo->services(),'reservations'=>$repo->reservations(),'relations'=>$repo->relations(),
    'objects'=>$repo->objectOptions(),'address-spaces'=>$planner->spaces(),'networks'=>$planner->networks(),'topology'=>$repo->topology(),
    'network-check'=>(new IpDesigner\NetworkCheck($f['db']))->run(),
    'planner/analyze/'.$f['network']=>$planner->analysis($f['network'])];
echo json_encode($data,JSON_THROW_ON_ERROR);
