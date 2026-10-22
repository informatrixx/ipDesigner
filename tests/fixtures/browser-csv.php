<?php
declare(strict_types=1);
require __DIR__.'/network-check.php';
$f=networkCheckFixture();$transfer=new IpDesigner\HostCsvTransfer($f['db']);
$sample=IpDesigner\Csv::encode(['site','name','hostname','interface_name','network','ips'],[
    ['site'=>'Testsite','name'=>'Import-Host','hostname'=>'imported','interface_name'=>'eth0','network'=>'LAN','ips'=>''],
]);
$input=['csv'=>$sample,'allow_updates'=>false];$preview=$transfer->preview($input);
$applied=$transfer->apply($input+['revision'=>$preview['revision'],'plan_hash'=>$preview['plan_hash']]);
echo json_encode(['sample'=>$sample,'preview'=>$preview,'applied'=>$applied,'export'=>$transfer->export(),'template'=>$transfer->template()],JSON_THROW_ON_ERROR);
