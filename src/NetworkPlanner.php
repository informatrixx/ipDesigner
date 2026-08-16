<?php
declare(strict_types=1);

namespace IpDesigner;

use DomainException;
use InvalidArgumentException;
use PDO;

final class NetworkPlanner
{
    private Repository $repository;

    public function __construct(private readonly PDO $db)
    {
        $this->repository = new Repository($db);
    }

    private function all(string $sql, array $params=[]): array { $s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(); }
    private function one(string $sql, array $params=[]): ?array { $s=$this->db->prepare($sql);$s->execute($params);$r=$s->fetch();return $r===false?null:$r; }
    private static function text(array $data,string $key,string $default=''):string{return trim((string)($data[$key]??$default));}

    public function spaces(): array
    {
        $rows=$this->all("SELECT a.*, (SELECT COUNT(*) FROM network_nodes n WHERE n.address_space_id=a.id) network_count, (SELECT COUNT(*) FROM sites s WHERE s.address_space_id=a.id) site_count FROM address_spaces a ORDER BY a.start_int");
        foreach($rows as &$row){$row['max_ip']='';$row['warning_end']=$this->privateEnd((int)$row['start_int']);$range=$this->one('SELECT MIN(network_int) first,MAX(broadcast_int) last FROM network_nodes WHERE address_space_id=?',[$row['id']]);if($range&&$range['last']!==null){$cover=$this->covering((int)$row['start_int'],(int)$range['last']);$row['effective_cidr']=$cover['cidr'];$row['effective_start']=IpMath::toIp($cover['network']);$row['effective_end']=IpMath::toIp($cover['broadcast']);$row['allocated_start']=IpMath::toIp((int)$range['first']);$row['allocated_end']=IpMath::toIp((int)$range['last']);$row['leading_buffer']=max(0,(int)$row['start_int']-$cover['network']);}else{foreach(['effective_cidr','effective_start','effective_end','allocated_start','allocated_end']as$key)$row[$key]='';$row['leading_buffer']=0;}}
        return $rows;
    }

    public function saveSpace(array $data,?int $id=null):array
    {
        $name=self::text($data,'name');if($name==='')throw new InvalidArgumentException('Name ist erforderlich.');
        $start=IpMath::toInt(self::text($data,'start_ip'));$max=null;$routing='default';
        $reserve=(int)($data['reserve_percent']??25);if($reserve<0||$reserve>500)throw new InvalidArgumentException('Wachstumsreserve ist ungültig.');
        if($id){$current=$this->one('SELECT * FROM address_spaces WHERE id=?',[$id]);if(!$current)throw new DomainException('Adressraum nicht gefunden.');$existing=$this->one('SELECT MIN(network_int) min_ip FROM network_nodes WHERE address_space_id=?',[$id]);if($existing&&$existing['min_ip']!==null&&(int)$current['start_int']!==$start)throw new DomainException('Die Start-IP kann bei belegten Adressräumen nicht direkt geändert werden.');$this->db->prepare('UPDATE address_spaces SET name=?,start_ip=?,start_int=?,max_int=NULL,routing_domain=?,reserve_percent=?,description=? WHERE id=?')->execute([$name,IpMath::toIp($start),$start,$routing,$reserve,self::text($data,'description'),$id]);}
        else{$this->db->beginTransaction();try{$this->db->exec("INSERT INTO entities(kind) VALUES ('address_space')");$entityId=(int)$this->db->lastInsertId();$this->db->prepare('INSERT INTO address_spaces(entity_id,name,start_ip,start_int,max_int,routing_domain,reserve_percent,description) VALUES (?,?,?,?,?,?,?,?)')->execute([$entityId,$name,IpMath::toIp($start),$start,null,$routing,$reserve,self::text($data,'description')]);$id=(int)$this->db->lastInsertId();$this->db->commit();}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}}
        if((int)$this->one('SELECT COUNT(*) value FROM address_spaces')['value']===1)$this->db->prepare('UPDATE sites SET address_space_id=? WHERE address_space_id IS NULL')->execute([$id]);
        return $this->one('SELECT * FROM address_spaces WHERE id=?',[$id])??throw new DomainException('Adressraum nicht gefunden.');
    }

    public function deleteSpace(int $id):void
    {
        if($this->one('SELECT id FROM network_nodes WHERE address_space_id=? LIMIT 1',[$id]))throw new DomainException('Adressraum enthält noch Netze.');
        if($this->one('SELECT id FROM sites WHERE address_space_id=? LIMIT 1',[$id]))throw new DomainException('Adressraum enthält noch Sites. Verschiebe oder lösche sie zuerst.');
        $space=$this->one('SELECT entity_id FROM address_spaces WHERE id=?',[$id]);if(!$space)throw new DomainException('Adressraum nicht gefunden.');
        $s=$this->db->prepare('DELETE FROM entities WHERE id=?');$s->execute([(int)$space['entity_id']]);
    }

    public function networks(?int $spaceId=null):array
    {
        $where=$spaceId?'WHERE n.address_space_id=?':'';$rows=$this->all("SELECT n.*,e.kind entity_kind,s.name site_name,a.name space_name,
          (SELECT COUNT(*) FROM network_nodes c WHERE c.parent_id=n.id) children_count,
          ((SELECT COUNT(*) FROM network_interfaces i WHERE CASE WHEN n.allocation_type IN ('static_pool','dhcp_pool') THEN i.pool_id=n.id ELSE i.network_id=n.id END)+(SELECT COUNT(*) FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id WHERE CASE WHEN n.allocation_type IN ('static_pool','dhcp_pool') THEN i.pool_id=n.id ELSE i.network_id=n.id END)) assigned_count,
          (SELECT COUNT(*) FROM network_reservations r WHERE CASE WHEN n.allocation_type IN ('static_pool','dhcp_pool') THEN r.pool_id=n.id ELSE r.network_id=n.id END) reserved_count
          FROM network_nodes n JOIN entities e ON e.id=n.id JOIN address_spaces a ON a.id=n.address_space_id LEFT JOIN sites s ON s.id=n.site_id $where ORDER BY n.address_space_id,COALESCE(n.parent_id,0),n.sort_order,n.network_int",$spaceId?[$spaceId]:[]);
        $byId=array_column($rows,null,'id');foreach($rows as &$r){$r=$this->networkOutput($r);$l3=null;for($cursor=(int)$r['id'];isset($byId[$cursor]);$cursor=(int)($byId[$cursor]['parent_id']??0)){if(!empty($byId[$cursor]['l3_enabled'])){$l3=$cursor;break;}if($byId[$cursor]['parent_id']===null)break;}$r['l3_network_id']=$l3;}return $rows;
    }

    public function network(int $id):array
    {
        foreach($this->networks()as$n)if((int)$n['id']===$id)return$n;throw new DomainException('Netz nicht gefunden.');
    }

    public function saveNetwork(array $data,int $id):array
    {
        $current=$this->one('SELECT * FROM network_nodes WHERE id=?',[$id]);
        if(!$current)throw new DomainException('Netz nicht gefunden.');
        $name=self::text($data,'name');if($name==='')throw new InvalidArgumentException('Name ist erforderlich.');
        $siteId=($data['site_id']??'')===''?null:(int)$data['site_id'];
        if($current['node_type']==='leaf'&&$siteId===null)throw new InvalidArgumentException('Ein Host-Subnetz muss einer Site zugeordnet sein.');
        if($siteId!==null&&!$this->one('SELECT id FROM sites WHERE id=?',[$siteId]))throw new InvalidArgumentException('Site ist ungültig.');
        if($siteId!==null&&$this->one('SELECT id FROM network_nodes WHERE site_id=? AND lower(name)=lower(?) AND id<>? LIMIT 1',[$siteId,$name,$id]))throw new DomainException('In dieser Site existiert bereits ein Objekt namens '.$name.'.');
        if($siteId!==null&&$this->one('SELECT i.id FROM network_interfaces i JOIN hosts h ON h.id=i.host_id WHERE i.network_id=? AND h.site_id<>? LIMIT 1',[$id,$siteId]))throw new DomainException('Vorhandene Hosts gehören zu einer anderen Site.');
        $requested=max(0,(int)($data['requested_hosts']??0));$reserve=(int)($data['reserve_percent']??25);
        if($reserve<0||$reserve>500)throw new InvalidArgumentException('Wachstumsreserve ist ungültig.');
        $vlan=($data['vlan_id']??'')===''?null:(int)$data['vlan_id'];if($vlan!==null&&($vlan<1||$vlan>4094))throw new InvalidArgumentException('VLAN-ID muss zwischen 1 und 4094 liegen.');
        $this->db->prepare('UPDATE network_nodes SET site_id=?,name=?,requested_hosts=?,reserve_percent=?,vlan_id=?,vrf_name=?,description=? WHERE id=?')->execute([$siteId,$name,$requested,$reserve,$vlan,self::text($data,'vrf_name'),self::text($data,'description'),$id]);
        return $this->network($id);
    }

    public function recommendNetwork(array $data):array
    {
        $spaceId=(int)($data['address_space_id']??0);$space=$this->one('SELECT * FROM address_spaces WHERE id=?',[$spaceId]);if(!$space)throw new InvalidArgumentException('Adressraum ist ungültig.');
        $parentId=empty($data['parent_id'])?null:(int)$data['parent_id'];$parent=$parentId?$this->one('SELECT * FROM network_nodes WHERE id=?',[$parentId]):null;
        if($parent&&((int)$parent['address_space_id']!==$spaceId||$parent['node_type']!=='container'))throw new DomainException('Elternbereich ist ungültig oder kein Container.');
        $hosts=max(0,(int)($data['requested_hosts']??0));$reserve=isset($data['reserve_percent'])?(int)$data['reserve_percent']:(int)$space['reserve_percent'];
        $target=max(3,(int)ceil($hosts*(1+$reserve/100))+1);$prefix=isset($data['prefix'])&&$data['prefix']!==''?(int)$data['prefix']:IpMath::prefixForHosts($target);
        if($prefix>29)$prefix=29;if($prefix<0||$prefix>32)throw new InvalidArgumentException('Präfix ist ungültig.');
        $size=2**(32-$prefix);[$start,$end]=$this->findFit($space,$parent,$size);if($virtual=$this->one('SELECT name FROM virtual_networks WHERE network_int<=? AND broadcast_int>=? LIMIT 1',[$end,$start]))throw new DomainException('Vorschlag überlappt mit dem virtuellen Netz '.$virtual['name'].'.');
        $cidr=IpMath::toIp($start).'/'.$prefix;$parsed=IpMath::parseCidr($cidr);$warning=$this->warning($space,$end);
        return ['input'=>['address_space_id'=>$spaceId,'parent_id'=>$parentId,'site_id'=>empty($data['site_id'])?($parent['site_id']??null):(int)$data['site_id'],'name'=>self::text($data,'name','Neuer Bereich'),'node_type'=>self::text($data,'node_type','leaf'),'requested_hosts'=>$hosts,'reserve_percent'=>$reserve,'vlan_id'=>$data['vlan_id']??null,'vrf_name'=>self::text($data,'vrf_name'),'description'=>self::text($data,'description')],
          'cidr'=>$cidr,'usable'=>$parsed['usable'],'host_capacity'=>max(0,$parsed['usable']-1),'gateway'=>IpMath::toIp($parsed['first_usable']),'warning'=>$warning,'revision'=>$this->revision($spaceId,$parentId)];
    }

    public function applyNetwork(array $data):array
    {
        $expected=self::text($data,'expected_cidr');$proposal=$this->recommendNetwork($data['input']??$data);
        if($expected!==''&&$expected!==$proposal['cidr'])throw new DomainException('Die Belegung hat sich geändert; bitte Vorschlag neu berechnen.');
        if(isset($data['revision'])&&!hash_equals((string)$data['revision'],$proposal['revision']))throw new DomainException('Der Vorschlag ist veraltet.');
        $i=$proposal['input'];$type=in_array($i['node_type'],['container','leaf'],true)?$i['node_type']:'leaf';$cidr=IpMath::parseCidr($proposal['cidr']);
        if($type==='leaf'&&empty($i['site_id']))throw new InvalidArgumentException('Ein Host-Subnetz muss einer Site zugeordnet sein.');
        $this->db->beginTransaction();try{$this->db->prepare('INSERT INTO entities(kind) VALUES (?)')->execute([$type==='leaf'?'subnet':'block']);$id=(int)$this->db->lastInsertId();$this->db->prepare('INSERT INTO network_nodes(id,address_space_id,parent_id,site_id,name,node_type,cidr,network_int,broadcast_int,prefix,requested_hosts,reserve_percent,gateway_int,vlan_id,vrf_name,description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$i['address_space_id'],$i['parent_id'],$i['site_id'],$i['name'],$type,$cidr['cidr'],$cidr['network'],$cidr['broadcast'],$cidr['prefix'],$i['requested_hosts'],$i['reserve_percent'],$type==='leaf'?$cidr['first_usable']:null,$i['vlan_id']?:null,$i['vrf_name'],$i['description']]);$this->db->commit();return$this->network($id);}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    public function analysis(int $id):array
    {
        $n=$this->network($id);$expansion=$this->expansion($id);$splits=[];
        for($parts=2;$parts<=8;$parts*=2){$newPrefix=(int)$n['prefix']+(int)log($parts,2);if($newPrefix<=32){$size=2**(32-$newPrefix);$usable=$newPrefix<=30?$size-2:$size;$splits[]=['parts'=>$parts,'prefix'=>$newPrefix,'cidr_size'=>$size,'hosts_per_subnet'=>max(0,$usable-1)];}}
        $status=$n['free_count']<=0?'full':($n['utilization']>=80?'warning':'ok');
        return ['network'=>$n,'status'=>$status,'expansion'=>$expansion,'splits'=>$splits,'recommendations'=>$this->recommendationTexts($n,$expansion)];
    }

    public function applySplit(int $id,int $parts):array
    {
        if(!in_array($parts,[2,4,8],true))throw new InvalidArgumentException('Teilungszahl ist ungültig.');$n=$this->network($id);
        if((int)$n['children_count']>0)throw new DomainException('Bereich besitzt bereits Kinder.');$newPrefix=(int)$n['prefix']+(int)log($parts,2);if($newPrefix>32)throw new DomainException('Netz kann nicht so weit geteilt werden.');
        $childSize=2**(32-$newPrefix);$this->db->beginTransaction();try{$this->db->prepare("UPDATE network_nodes SET node_type='container',gateway_int=NULL WHERE id=?")->execute([$id]);$children=[];for($i=0;$i<$parts;$i++){$start=(int)$n['network_int']+$i*$childSize;$cidr=IpMath::toIp($start).'/'.$newPrefix;$p=IpMath::parseCidr($cidr);$this->db->prepare("INSERT INTO entities(kind) VALUES ('subnet')")->execute();$childId=(int)$this->db->lastInsertId();$this->db->prepare('INSERT INTO network_nodes(id,address_space_id,parent_id,site_id,name,node_type,cidr,network_int,broadcast_int,prefix,requested_hosts,reserve_percent,gateway_int,description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$childId,$n['address_space_id'],$id,$n['site_id'],$n['name'].' '.($i+1),'leaf',$cidr,$p['network'],$p['broadcast'],$newPrefix,0,$n['reserve_percent'],$p['first_usable'],'Aus Split von '.$n['cidr']]);$this->db->prepare('UPDATE network_interfaces SET network_id=? WHERE network_id=? AND ip_int BETWEEN ? AND ?')->execute([$childId,$id,$p['network'],$p['broadcast']]);$this->db->prepare('UPDATE network_reservations SET network_id=? WHERE network_id=? AND ip_int BETWEEN ? AND ?')->execute([$childId,$id,$p['network'],$p['broadcast']]);$children[]=$childId;}$this->db->commit();return['parent'=>$this->network($id),'children'=>array_map(fn($childId)=>$this->network($childId),$children)];}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    public function applyExpansion(int $id):array
    {
        $x=$this->expansion($id);if(!$x['possible'])throw new DomainException($x['reason']??'Netz kann nicht erweitert werden.');$p=IpMath::parseCidr($x['cidr']);$this->db->prepare('UPDATE network_nodes SET cidr=?,network_int=?,broadcast_int=?,prefix=? WHERE id=?')->execute([$p['cidr'],$p['network'],$p['broadcast'],$p['prefix'],$id]);return$this->network($id);
    }

    public function hostCandidates(int $siteId):array
    {
        $result=[];foreach($this->networks()as$n){if((int)$n['site_id']!==$siteId)continue;if($n['node_role']==='subnet'&&(int)$n['children_count']===0){$ip=$this->nextIp((int)$n['id']);$networkId=(int)$n['id'];$poolId=null;}elseif($n['node_role']==='static_pool'&&!empty($n['l3_network_id'])){$ip=$this->nextPoolIp($n);$networkId=(int)$n['l3_network_id'];$poolId=(int)$n['id'];}else continue;if($ip!==null)$result[]=['network_id'=>$networkId,'pool_id'=>$poolId,'network_name'=>$n['name'],'site_id'=>$siteId,'site_name'=>$n['site_name'],'cidr'=>$n['cidr'],'ip'=>IpMath::toIp($ip),'free_count'=>$n['free_count'],'utilization'=>$n['utilization']];}usort($result,fn($a,$b)=>$a['free_count']<=>$b['free_count']);return$result;
    }

    public function applyHost(array $data):array
    {
        $networkId=(int)($data['network_id']??0);$poolId=(int)($data['pool_id']??0)?:null;$n=$this->network($networkId);if(empty($n['site_id'])||!($n['node_role']==='subnet'||$n['node_role']==='l3_subnet'))throw new DomainException('Ziel ist kein Layer-3-Subnetz.');if($poolId){$pool=$this->network($poolId);if($pool['node_role']!=='static_pool'||(int)$pool['l3_network_id']!==$networkId)throw new DomainException('Ziel ist kein statischer Adresspool.');$ip=$this->nextPoolIp($pool);}else$ip=$this->nextIp($networkId);if($ip===null)throw new DomainException('Subnetz oder Pool besitzt keine freie Hostadresse.');
        $hostData=$data['host']??[];$hostData['site_id']=$n['site_id'];$this->db->beginTransaction();try{$host=$this->repository->saveHost($hostData);$interface=$this->repository->saveInterface(['host_id'=>$host['id'],'network_id'=>$networkId,'pool_id'=>$poolId,'name'=>$data['interface_name']??'eth0','ip'=>IpMath::toIp($ip),'dns_name'=>$data['dns_name']??'']);$this->db->commit();return['host'=>$host,'interface'=>$interface];}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    public function deleteNetwork(int $id):void
    {
        if($this->one('SELECT id FROM network_nodes WHERE parent_id=? LIMIT 1',[$id])||$this->one('SELECT id FROM network_interfaces WHERE network_id=? LIMIT 1',[$id]))throw new DomainException('Netz enthält Kinder oder Hosts.');$s=$this->db->prepare('DELETE FROM entities WHERE id=?');$s->execute([$id]);if(!$s->rowCount())throw new DomainException('Netz nicht gefunden.');
    }

    private function findFit(array$space,?array$parent,int$size):array
    {
        $lower=$parent?(int)$parent['network_int']:(int)$space['start_int'];$upper=$parent?(int)$parent['broadcast_int']:($space['max_int']===null?4294967295:(int)$space['max_int']);$params=[];
        if($parent){$rows=$this->all('SELECT network_int,broadcast_int FROM network_nodes WHERE parent_id=? ORDER BY network_int',[$parent['id']]);}else{$rows=$this->all('SELECT network_int,broadcast_int FROM network_nodes WHERE parent_id IS NULL ORDER BY network_int');}
        $candidate=(int)(ceil($lower/$size)*$size);foreach($rows as$r){if((int)$r['broadcast_int']<$candidate)continue;if($candidate+$size-1<(int)$r['network_int'])break;$candidate=(int)(ceil(((int)$r['broadcast_int']+1)/$size)*$size);}if($candidate+$size-1>$upper)throw new DomainException('Kein ausreichend großer, ausgerichteter Bereich ist frei.');return[$candidate,$candidate+$size-1];
    }

    private function expansion(int$id):array
    {
        $n=$this->one('SELECT * FROM network_nodes WHERE id=?',[$id]);if(!$n)return['possible'=>false,'reason'=>'Netz nicht gefunden.'];if((int)$n['prefix']===0)return['possible'=>false,'reason'=>'/0 kann nicht erweitert werden.'];$size=(int)$n['broadcast_int']-(int)$n['network_int']+1;$newSize=$size*2;$start=intdiv((int)$n['network_int'],$newSize)*$newSize;$end=$start+$newSize-1;
        if($n['parent_id']){$parent=$this->one('SELECT * FROM network_nodes WHERE id=?',[$n['parent_id']]);if($start<(int)$parent['network_int']||$end>(int)$parent['broadcast_int'])return['possible'=>false,'reason'=>'Elternbereich ist zu klein.'];$collision=$this->one('SELECT id FROM network_nodes WHERE parent_id=? AND id<>? AND network_int<=? AND broadcast_int>=? LIMIT 1',[$n['parent_id'],$id,$end,$start]);}else{$space=$this->one('SELECT * FROM address_spaces WHERE id=?',[$n['address_space_id']]);if($start<(int)$space['start_int']||($space['max_int']!==null&&$end>(int)$space['max_int']))return['possible'=>false,'reason'=>'Adressraumgrenze verhindert die Erweiterung.'];$collision=$this->one('SELECT id FROM network_nodes WHERE parent_id IS NULL AND id<>? AND network_int<=? AND broadcast_int>=? LIMIT 1',[$id,$end,$start]);}
        if($collision)return['possible'=>false,'reason'=>'Benachbarter Buddy-Bereich ist belegt.'];if($virtual=$this->one('SELECT name FROM virtual_networks WHERE network_int<=? AND broadcast_int>=? LIMIT 1',[$end,$start]))return['possible'=>false,'reason'=>'Virtuelles Netz '.$virtual['name'].' belegt den Erweiterungsbereich.'];return['possible'=>true,'cidr'=>IpMath::toIp($start).'/'.((int)$n['prefix']-1)];
    }

    private function nextIp(int$networkId):?int
    {
        $n=$this->one('SELECT * FROM network_nodes WHERE id=?',[$networkId]);if(!$n)return null;$p=IpMath::parseCidr($n['cidr']);$used=[];foreach($this->all('SELECT ip_int FROM network_interfaces WHERE network_id=? UNION SELECT a.ip_int FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id WHERE i.network_id=? UNION SELECT ip_int FROM network_reservations WHERE network_id=?',[$networkId,$networkId,$networkId])as$r)$used[(int)$r['ip_int']]=true;$used[(int)$n['gateway_int']]=true;for($ip=$p['first_usable'];$ip<=$p['last_usable'];$ip++)if(!isset($used[$ip]))return$ip;return null;
    }

    private function nextPoolIp(array$pool):?int
    {
        $start=(int)$pool['network_int']+(int)($pool['pool_start_offset']??0);$end=(int)$pool['network_int']+(int)($pool['pool_end_offset']??((int)$pool['broadcast_int']-(int)$pool['network_int']));$used=[];foreach($this->all('SELECT ip_int FROM network_interfaces WHERE pool_id=? UNION SELECT a.ip_int FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id WHERE i.pool_id=? UNION SELECT ip_int FROM network_reservations WHERE pool_id=?',[$pool['id'],$pool['id'],$pool['id']])as$row)$used[(int)$row['ip_int']]=true;for($ip=$start;$ip<=$end;$ip++)if(!isset($used[$ip]))return$ip;return null;
    }

    private function networkOutput(array$r):array
    {
        $p=IpMath::parseCidr($r['cidr']);$gateway=$r['gateway_int']===null?0:1;$allocation=$r['allocation_type']??'subnet';$r['node_role']=$allocation==='static_pool'?'static_pool':($allocation==='dhcp_pool'?'dhcp_pool':(!empty($r['l3_enabled'])?'l3_subnet':($r['node_type']==='leaf'?'subnet':(($r['entity_kind']??'block')==='subnet'?'aggregate':'group'))));$r['network']=IpMath::toIp($p['network']);$r['broadcast']=IpMath::toIp($p['broadcast']);$r['manual_start_ip']=$r['manual_start_int']===null?'':IpMath::toIp((int)$r['manual_start_int']);$r['gateway']=$r['gateway_int']===null?'':IpMath::toIp((int)$r['gateway_int']);$r['range_start']=in_array($allocation,['static_pool','dhcp_pool'],true)?IpMath::toIp((int)$r['network_int']+(int)($r['pool_start_offset']??0)):'';$r['range_end']=in_array($allocation,['static_pool','dhcp_pool'],true)?IpMath::toIp((int)$r['network_int']+(int)($r['pool_end_offset']??((int)$r['broadcast_int']-(int)$r['network_int']))):'';$r['size']=$p['size'];$r['usable']=in_array($allocation,['static_pool','dhcp_pool'],true)?max(0,(int)($r['pool_end_offset']??0)-(int)($r['pool_start_offset']??0)+1):$p['usable'];$r['host_capacity']=in_array($allocation,['static_pool','dhcp_pool'],true)?$r['usable']:($r['node_type']==='leaf'||!empty($r['l3_enabled'])?max(0,$p['usable']-$gateway):$p['size']);$r['free_count']=max(0,$r['host_capacity']-(int)$r['assigned_count']-(int)$r['reserved_count']);$r['utilization']=$r['host_capacity']?round(100*((int)$r['assigned_count']+(int)$r['reserved_count'])/$r['host_capacity'],2):0;return$r;
    }
    private function revision(int$spaceId,?int$parentId):string{$rows=$parentId?$this->all('SELECT id,cidr FROM network_nodes WHERE parent_id=? ORDER BY id',[$parentId]):$this->all('SELECT id,cidr FROM network_nodes WHERE parent_id IS NULL ORDER BY id');return sha1(json_encode([$spaceId,$parentId,$rows]));}
    private function privateEnd(int$start):string{$ranges=[['10.0.0.0','10.255.255.255'],['172.16.0.0','172.31.255.255'],['192.168.0.0','192.168.255.255']];foreach($ranges as[$a,$b])if($start>=IpMath::toInt($a)&&$start<=IpMath::toInt($b))return$b;return'';}
    private function warning(array$space,int$end):string{$private=$this->privateEnd((int)$space['start_int']);if($private!==''&&$end>IpMath::toInt($private))return'Der Vorschlag verlässt den privaten Bereich bei '.$private.'.';if($space['max_int']!==null&&$end>(int)$space['max_int'])return'Der Vorschlag überschreitet die Obergrenze.';return'';}
    private function covering(int$start,int$end):array{$prefix=32;while($prefix>0){$size=2**(32-$prefix);$network=intdiv($start,$size)*$size;if($network+$size-1>=$end)break;$prefix--;}$size=2**(32-$prefix);$network=intdiv($start,$size)*$size;return['cidr'=>IpMath::toIp($network).'/'.$prefix,'network'=>$network,'broadcast'=>$network+$size-1];}
    private function recommendationTexts(array$n,array$x):array{$r=[];if($n['free_count']<=0)$r[]='Keine freie Hostadresse mehr verfügbar.';elseif($n['utilization']>=80)$r[]='Auslastung über 80 %: Erweiterung oder neues Geschwisternetz prüfen.';else$r[]=$n['free_count'].' Hostadressen sind aktuell frei.';$r[]=$x['possible']?'Erweiterung auf '.$x['cidr'].' ist ohne Umnummerierung möglich.':($x['reason']??'Keine Erweiterung verfügbar.');if($n['node_type']==='leaf')$r[]='Das Netz kann in 2, 4 oder 8 gleich große Blatt-Subnetze geteilt werden.';return$r;}
}
