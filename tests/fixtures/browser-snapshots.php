<?php
declare(strict_types=1);
require __DIR__.'/network-check.php';
require_once dirname(__DIR__,2).'/src/PlanningSnapshots.php';
$f=networkCheckFixture(); $snapshots=new IpDesigner\PlanningSnapshots($f['db']);
$initialEpoch=$snapshots->epoch();
$created=$snapshots->create(['name'=>'Vor Umbau','description'=>'Vollständiger Beispielplan']);
$f['db']->exec("UPDATE hosts SET description='Nachträglich geändert'");
$preview=$snapshots->preview((int)$created['id']); $list=$snapshots->list();
$restored=$snapshots->restore((int)$created['id'],['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash'],'confirm_restore'=>true]);
$f['db']->exec('UPDATE network_nodes SET prefix=20 WHERE id='.(int)$f['network']);
$errors=$snapshots->create(['name'=>'Historischer Stand mit Fehler']);
$errorPreview=$snapshots->preview((int)$errors['id']);
echo json_encode(['epoch'=>$initialEpoch,'list'=>$list,'created'=>$created,'preview'=>$preview,'restored'=>$restored,'after_list'=>$snapshots->list(),'error_preview'=>$errorPreview],JSON_THROW_ON_ERROR);
