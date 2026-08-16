<?php
declare(strict_types=1);

namespace IpDesigner;

use PDO;

final class Database
{
    public static function connect(?string $path = null): PDO
    {
        $path ??= getenv('IPDESIGNER_DB_PATH') ?: dirname(__DIR__) . '/data/ipdesigner.sqlite';
        $directory = dirname($path);
        if (!is_dir($directory)) mkdir($directory, 0770, true);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::migrate($pdo);
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        if ($sql === false) throw new \RuntimeException('Schema konnte nicht geladen werden.');
        $pdo->exec($sql);
        $siteColumns = array_column($pdo->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
        if (!in_array('domain_name', $siteColumns, true)) {
            $pdo->exec("ALTER TABLE sites ADD COLUMN domain_name TEXT NOT NULL DEFAULT ''");
        }
        $hostColumns = array_column($pdo->query('PRAGMA table_info(hosts)')->fetchAll(), 'name');
        if (!in_array('hostname', $hostColumns, true)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN hostname TEXT NOT NULL DEFAULT ''");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS dns_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
            interface_id INTEGER REFERENCES interfaces(id) ON DELETE CASCADE,
            kind TEXT NOT NULL CHECK (kind IN ('primary','host_alias','interface_alias')),
            input_name TEXT NOT NULL,
            is_fqdn INTEGER NOT NULL DEFAULT 0 CHECK (is_fqdn IN (0,1)),
            fqdn TEXT NOT NULL UNIQUE
        )");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_dns_primary_host ON dns_names(host_id) WHERE kind='primary'");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dns_host ON dns_names(host_id, kind)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (2)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS address_spaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, start_ip TEXT NOT NULL,
            start_int INTEGER NOT NULL UNIQUE, max_int INTEGER, reserve_percent INTEGER NOT NULL DEFAULT 25,
            description TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_nodes (
            id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
            address_space_id INTEGER NOT NULL REFERENCES address_spaces(id) ON DELETE RESTRICT,
            parent_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT,
            site_id INTEGER REFERENCES sites(id) ON DELETE RESTRICT,
            name TEXT NOT NULL, node_type TEXT NOT NULL CHECK (node_type IN ('container','leaf')),
            cidr TEXT NOT NULL UNIQUE, network_int INTEGER NOT NULL, broadcast_int INTEGER NOT NULL,
            prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 32), requested_hosts INTEGER NOT NULL DEFAULT 0,
            reserve_percent INTEGER NOT NULL DEFAULT 25, gateway_int INTEGER, vlan_id INTEGER,
            vrf_name TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', UNIQUE(parent_id,name)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_tree ON network_nodes(address_space_id,parent_id,network_int)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_interfaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
            network_id INTEGER NOT NULL REFERENCES network_nodes(id) ON DELETE RESTRICT, name TEXT NOT NULL,
            mac_address TEXT, ip_int INTEGER NOT NULL UNIQUE, dns_name TEXT NOT NULL DEFAULT '', UNIQUE(host_id,name)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_interfaces_node ON network_interfaces(network_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, network_id INTEGER NOT NULL REFERENCES network_nodes(id) ON DELETE RESTRICT,
            ip_int INTEGER NOT NULL UNIQUE, label TEXT NOT NULL, reason TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_reservations_node ON network_reservations(network_id)');
        $dnsColumns = array_column($pdo->query('PRAGMA table_info(dns_names)')->fetchAll(), 'name');
        if (!in_array('network_interface_id', $dnsColumns, true)) {
            $pdo->exec('ALTER TABLE dns_names ADD COLUMN network_interface_id INTEGER REFERENCES network_interfaces(id) ON DELETE CASCADE');
        }
        $spaceColumns = array_column($pdo->query('PRAGMA table_info(address_spaces)')->fetchAll(), 'name');
        if (!in_array('routing_domain', $spaceColumns, true)) {
            $pdo->exec("ALTER TABLE address_spaces ADD COLUMN routing_domain TEXT NOT NULL DEFAULT 'default'");
        }
        $networkColumns = array_column($pdo->query('PRAGMA table_info(network_nodes)')->fetchAll(), 'name');
        if (!in_array('sort_order', $networkColumns, true)) {
            $pdo->exec('ALTER TABLE network_nodes ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE network_nodes SET sort_order=id WHERE sort_order=0');
        }
        self::relaxRoutingUniqueness($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS replan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            address_space_id INTEGER NOT NULL REFERENCES address_spaces(id) ON DELETE CASCADE,
            revision_before TEXT NOT NULL, revision_after TEXT NOT NULL,
            before_snapshot TEXT NOT NULL, after_snapshot TEXT NOT NULL,
            warnings TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_replan_space ON replan_runs(address_space_id,id DESC)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (4)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (3)');
        self::addAddressSpaceHierarchy($pdo);
        self::addRouterPlanning($pdo);
        self::addManualNetworkPrefix($pdo);
        self::addManualNetworkStart($pdo);
        self::addLayer3Pools($pdo);
        self::addScalablePoolRanges($pdo);
        self::addVirtualNetworks($pdo);
        self::addMultipleInterfaceAddresses($pdo);
        self::allowSharedVpnInterfaceNames($pdo);
        self::scopeObjectNamesToSites($pdo);
        self::addSiteAllocationMode($pdo);
    }

    private static function addSiteAllocationMode(PDO $pdo):void
    {
        $columns=array_column($pdo->query('PRAGMA table_info(sites)')->fetchAll(),'name');
        if(!in_array('allocation_mode',$columns,true))$pdo->exec("ALTER TABLE sites ADD COLUMN allocation_mode TEXT NOT NULL DEFAULT 'range' CHECK (allocation_mode IN ('range','cidr'))");
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (15)');
    }

    private static function scopeObjectNamesToSites(PDO $pdo):void
    {
        $networkDuplicate=$pdo->query("SELECT s.name site_name,n.name object_name FROM network_nodes n JOIN sites s ON s.id=n.site_id GROUP BY n.site_id,lower(n.name) HAVING COUNT(*)>1 LIMIT 1")->fetch();
        if($networkDuplicate)throw new \RuntimeException('Migration abgebrochen: In der Site '.$networkDuplicate['site_name'].' existiert das Netzobjekt '.$networkDuplicate['object_name'].' mehrfach.');
        $hostDuplicate=$pdo->query("SELECT s.name site_name,h.name object_name FROM hosts h JOIN sites s ON s.id=h.site_id GROUP BY h.site_id,lower(h.name) HAVING COUNT(*)>1 LIMIT 1")->fetch();
        if($hostDuplicate)throw new \RuntimeException('Migration abgebrochen: In der Site '.$hostDuplicate['site_name'].' existiert der Host '.$hostDuplicate['object_name'].' mehrfach.');

        $definition=(string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='hosts'")->fetchColumn();
        if(preg_match('/name\s+TEXT\s+NOT\s+NULL\s+UNIQUE/i',$definition)){
            $pdo->exec('PRAGMA foreign_keys=OFF');
            try{
                $pdo->beginTransaction();
                $pdo->exec("CREATE TABLE hosts_v14 (
                    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
                    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE RESTRICT,
                    name TEXT NOT NULL, hostname TEXT NOT NULL DEFAULT '',
                    type TEXT NOT NULL DEFAULT 'server' CHECK (type IN ('server','vm','network','client','appliance','other')),
                    status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','degraded','inactive')),
                    description TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
                    is_router INTEGER NOT NULL DEFAULT 0 CHECK (is_router IN (0,1))
                )");
                $pdo->exec('INSERT INTO hosts_v14(id,site_id,name,hostname,type,status,description,notes,is_router) SELECT id,site_id,name,hostname,type,status,description,notes,is_router FROM hosts');
                $pdo->exec('DROP TABLE hosts');
                $pdo->exec('ALTER TABLE hosts_v14 RENAME TO hosts');
                $pdo->exec('CREATE UNIQUE INDEX idx_hosts_site_name ON hosts(site_id,name COLLATE NOCASE)');
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_network_nodes_site_name ON network_nodes(site_id,name COLLATE NOCASE)');
                $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (14)');
                $pdo->commit();
            }catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}finally{$pdo->exec('PRAGMA foreign_keys=ON');}
        }else{
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_hosts_site_name ON hosts(site_id,name COLLATE NOCASE)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_network_nodes_site_name ON network_nodes(site_id,name COLLATE NOCASE)');
            $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (14)');
        }
        if($pdo->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Migration der sitebezogenen Objektnamen ist inkonsistent.');
    }

    private static function addMultipleInterfaceAddresses(PDO $pdo):void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS network_interface_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            interface_id INTEGER NOT NULL REFERENCES network_interfaces(id) ON DELETE CASCADE,
            ip_int INTEGER NOT NULL UNIQUE,
            UNIQUE(interface_id,ip_int)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_interface_addresses_interface ON network_interface_addresses(interface_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS vpn_interface_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            interface_id INTEGER NOT NULL REFERENCES vpn_interfaces(id) ON DELETE CASCADE,
            ip_int INTEGER NOT NULL UNIQUE,
            UNIQUE(interface_id,ip_int)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vpn_interface_addresses_interface ON vpn_interface_addresses(interface_id)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (12)');
    }

    private static function allowSharedVpnInterfaceNames(PDO $pdo):void
    {
        $definition=(string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='vpn_interfaces'")->fetchColumn();
        if(!preg_match('/UNIQUE\s*\(\s*router_id\s*,\s*name\s*\)/i',$definition)){$pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (13)');return;}
        $pdo->exec('PRAGMA foreign_keys=OFF');
        try{
            $pdo->beginTransaction();
            $pdo->exec("CREATE TABLE vpn_interfaces_v13 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                virtual_network_id INTEGER NOT NULL REFERENCES virtual_networks(id) ON DELETE CASCADE,
                router_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE RESTRICT,
                name TEXT NOT NULL,
                ip_int INTEGER NOT NULL UNIQUE,
                UNIQUE(virtual_network_id,router_id)
            )");
            $pdo->exec('INSERT INTO vpn_interfaces_v13(id,virtual_network_id,router_id,name,ip_int) SELECT id,virtual_network_id,router_id,name,ip_int FROM vpn_interfaces');
            $pdo->exec("CREATE TABLE vpn_interface_addresses_v13 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interface_id INTEGER NOT NULL REFERENCES vpn_interfaces_v13(id) ON DELETE CASCADE,
                ip_int INTEGER NOT NULL UNIQUE,
                UNIQUE(interface_id,ip_int)
            )");
            $pdo->exec('INSERT INTO vpn_interface_addresses_v13(id,interface_id,ip_int) SELECT id,interface_id,ip_int FROM vpn_interface_addresses');
            $pdo->exec('DROP TABLE vpn_interface_addresses');
            $pdo->exec('DROP TABLE vpn_interfaces');
            $pdo->exec('ALTER TABLE vpn_interfaces_v13 RENAME TO vpn_interfaces');
            $pdo->exec('ALTER TABLE vpn_interface_addresses_v13 RENAME TO vpn_interface_addresses');
            $pdo->exec('CREATE INDEX idx_vpn_interfaces_network ON vpn_interfaces(virtual_network_id,ip_int)');
            $pdo->exec('CREATE INDEX idx_vpn_interface_addresses_interface ON vpn_interface_addresses(interface_id)');
            $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (13)');
            $pdo->commit();
        }catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}finally{$pdo->exec('PRAGMA foreign_keys=ON');}
        if($pdo->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Migration gemeinsam genutzter VPN-Interfacenamen ist inkonsistent.');
    }

    private static function addVirtualNetworks(PDO $pdo):void
    {
        $entitySql=(string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='entities'")->fetchColumn();
        if(!str_contains($entitySql,"'virtual_network'")){
            $pdo->exec('PRAGMA foreign_keys=OFF');
            try{
                $pdo->exec("CREATE TABLE entities_v9 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    kind TEXT NOT NULL CHECK (kind IN ('address_space','site','block','subnet','host','service','virtual_network')),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )");
                $pdo->exec('INSERT INTO entities_v9 SELECT id,kind,created_at,updated_at FROM entities');
                $pdo->exec('DROP TABLE entities');
                $pdo->exec('ALTER TABLE entities_v9 RENAME TO entities');
            }finally{$pdo->exec('PRAGMA foreign_keys=ON');}
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS virtual_networks (
            id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
            network_type TEXT NOT NULL CHECK (network_type IN ('vpn','docker_bridge')),
            name TEXT NOT NULL UNIQUE, cidr TEXT NOT NULL,
            network_int INTEGER NOT NULL, broadcast_int INTEGER NOT NULL,
            prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 31), gateway_int INTEGER,
            owner_host_id INTEGER REFERENCES hosts(id) ON DELETE RESTRICT,
            driver TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_virtual_network_range ON virtual_networks(network_int,broadcast_int)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_virtual_network_owner ON virtual_networks(owner_host_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS vpn_interfaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            virtual_network_id INTEGER NOT NULL REFERENCES virtual_networks(id) ON DELETE CASCADE,
            router_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE RESTRICT,
            name TEXT NOT NULL, ip_int INTEGER NOT NULL UNIQUE,
            UNIQUE(virtual_network_id,router_id), UNIQUE(router_id,name)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vpn_interfaces_network ON vpn_interfaces(virtual_network_id,ip_int)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (9)');
        if($pdo->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Migration der virtuellen Netze ist inkonsistent.');
    }

    private static function addLayer3Pools(PDO $pdo):void
    {
        $network=array_column($pdo->query('PRAGMA table_info(network_nodes)')->fetchAll(),'name');
        if(!in_array('l3_enabled',$network,true))$pdo->exec("ALTER TABLE network_nodes ADD COLUMN l3_enabled INTEGER NOT NULL DEFAULT 0 CHECK (l3_enabled IN (0,1))");
        if(!in_array('allocation_type',$network,true))$pdo->exec("ALTER TABLE network_nodes ADD COLUMN allocation_type TEXT NOT NULL DEFAULT 'subnet' CHECK (allocation_type IN ('subnet','static_pool','dhcp_pool'))");
        if(!in_array('pool_start_offset',$network,true))$pdo->exec('ALTER TABLE network_nodes ADD COLUMN pool_start_offset INTEGER');
        if(!in_array('pool_end_offset',$network,true))$pdo->exec('ALTER TABLE network_nodes ADD COLUMN pool_end_offset INTEGER');
        $interfaces=array_column($pdo->query('PRAGMA table_info(network_interfaces)')->fetchAll(),'name');
        if(!in_array('pool_id',$interfaces,true))$pdo->exec('ALTER TABLE network_interfaces ADD COLUMN pool_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT');
        $reservations=array_column($pdo->query('PRAGMA table_info(network_reservations)')->fetchAll(),'name');
        if(!in_array('pool_id',$reservations,true))$pdo->exec('ALTER TABLE network_reservations ADD COLUMN pool_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT');
        if(!in_array('reservation_type',$reservations,true))$pdo->exec("ALTER TABLE network_reservations ADD COLUMN reservation_type TEXT NOT NULL DEFAULT 'static' CHECK (reservation_type IN ('static','dhcp_reservation','dhcp_exclusion'))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_interfaces_pool ON network_interfaces(pool_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_network_reservations_pool ON network_reservations(pool_id)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (8)');
    }

    private static function addScalablePoolRanges(PDO $pdo):void
    {
        $columns=array_column($pdo->query('PRAGMA table_info(network_nodes)')->fetchAll(),'name');
        if(!in_array('pool_range_fixed',$columns,true)){
            $pdo->exec('ALTER TABLE network_nodes ADD COLUMN pool_range_fixed INTEGER NOT NULL DEFAULT 0 CHECK (pool_range_fixed IN (0,1))');
            $pdo->exec("UPDATE network_nodes SET pool_range_fixed=1 WHERE allocation_type IN ('static_pool','dhcp_pool') AND pool_start_offset IS NOT NULL AND pool_end_offset IS NOT NULL");
        }
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (11)');
    }

    private static function addManualNetworkPrefix(PDO $pdo):void
    {
        $columns=array_column($pdo->query('PRAGMA table_info(network_nodes)')->fetchAll(),'name');
        if(!in_array('manual_prefix',$columns,true))$pdo->exec('ALTER TABLE network_nodes ADD COLUMN manual_prefix INTEGER CHECK (manual_prefix IS NULL OR manual_prefix BETWEEN 0 AND 31)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (7)');
    }

    private static function addManualNetworkStart(PDO $pdo):void
    {
        $columns=array_column($pdo->query('PRAGMA table_info(network_nodes)')->fetchAll(),'name');
        if(!in_array('manual_start_int',$columns,true))$pdo->exec('ALTER TABLE network_nodes ADD COLUMN manual_start_int INTEGER CHECK (manual_start_int IS NULL OR manual_start_int BETWEEN 0 AND 4294967295)');
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (10)');
    }

    private static function addRouterPlanning(PDO $pdo): void
    {
        $hostColumns=array_column($pdo->query('PRAGMA table_info(hosts)')->fetchAll(),'name');
        if(!in_array('is_router',$hostColumns,true))$pdo->exec('ALTER TABLE hosts ADD COLUMN is_router INTEGER NOT NULL DEFAULT 0 CHECK (is_router IN (0,1))');
        $pdo->exec("CREATE TABLE IF NOT EXISTS gateway_bindings (
            network_id INTEGER PRIMARY KEY REFERENCES network_nodes(id) ON DELETE CASCADE,
            interface_id INTEGER NOT NULL UNIQUE REFERENCES network_interfaces(id) ON DELETE RESTRICT,
            address_offset INTEGER NOT NULL CHECK (address_offset > 0), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS static_routes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, router_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
            destination_cidr TEXT NOT NULL, destination_network_id INTEGER REFERENCES network_nodes(id) ON DELETE SET NULL,
            egress_interface_id INTEGER NOT NULL REFERENCES network_interfaces(id) ON DELETE RESTRICT,
            next_hop_int INTEGER NOT NULL, metric INTEGER NOT NULL DEFAULT 10 CHECK (metric BETWEEN 0 AND 65535),
            description TEXT NOT NULL DEFAULT '', UNIQUE(router_id,destination_cidr,egress_interface_id,next_hop_int)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_static_routes_router ON static_routes(router_id,destination_cidr)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_network_interfaces_ip_global ON network_interfaces(ip_int)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_network_reservations_ip_global ON network_reservations(ip_int)');
        $pdo->exec("UPDATE address_spaces SET routing_domain='default'");
        $pdo->exec("UPDATE network_nodes SET vrf_name=''");
        $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (6)');
    }

    private static function addAddressSpaceHierarchy(PDO $pdo): void
    {
        $siteColumns = array_column($pdo->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
        if (!in_array('address_space_id', $siteColumns, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN address_space_id INTEGER REFERENCES address_spaces(id) ON DELETE RESTRICT');
        }
        if (!in_array('sort_order', $siteColumns, true)) {
            $pdo->exec('ALTER TABLE sites ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE sites SET sort_order=id WHERE sort_order=0');
        }
        $spaceColumns = array_column($pdo->query('PRAGMA table_info(address_spaces)')->fetchAll(), 'name');
        if (!in_array('entity_id', $spaceColumns, true)) {
            $pdo->exec('ALTER TABLE address_spaces ADD COLUMN entity_id INTEGER REFERENCES entities(id) ON DELETE CASCADE');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_address_spaces_entity ON address_spaces(entity_id)');
        }

        $entitySql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='entities'")->fetchColumn();
        if (!str_contains($entitySql, "'address_space'")) {
            $pdo->exec('PRAGMA foreign_keys=OFF');
            try {
                $pdo->exec("CREATE TABLE entities_v5 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    kind TEXT NOT NULL CHECK (kind IN ('address_space','site','block','subnet','host','service')),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )");
                $pdo->exec('INSERT INTO entities_v5 SELECT id,kind,created_at,updated_at FROM entities');
                $pdo->exec('DROP TABLE entities');
                $pdo->exec('ALTER TABLE entities_v5 RENAME TO entities');
            } finally {
                $pdo->exec('PRAGMA foreign_keys=ON');
            }
        }

        $pdo->beginTransaction();
        try {
            foreach ($pdo->query('SELECT id FROM address_spaces WHERE entity_id IS NULL ORDER BY id')->fetchAll() as $space) {
                $pdo->exec("INSERT INTO entities(kind) VALUES ('address_space')");
                $entityId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('UPDATE address_spaces SET entity_id=? WHERE id=?');
                $stmt->execute([$entityId, (int)$space['id']]);
            }
            $spaceCount = (int)$pdo->query('SELECT COUNT(*) FROM address_spaces')->fetchColumn();
            if ($spaceCount === 1) {
                $pdo->exec('UPDATE sites SET address_space_id=(SELECT id FROM address_spaces LIMIT 1) WHERE address_space_id IS NULL');
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sites_space ON sites(address_space_id,sort_order,id)');
            $pdo->exec('INSERT OR IGNORE INTO schema_migrations(version) VALUES (5)');
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        if ($pdo->query('PRAGMA foreign_key_check')->fetch()) {
            throw new \RuntimeException('Migration der Adressraum-Site-Hierarchie ist inkonsistent.');
        }
    }

    private static function relaxRoutingUniqueness(PDO $pdo): void
    {
        $definition=(string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='network_nodes'")->fetchColumn();
        if(!str_contains($definition,'cidr TEXT NOT NULL UNIQUE'))return;
        $pdo->exec('PRAGMA foreign_keys=OFF');
        try {
            $pdo->exec("CREATE TABLE address_spaces_v4 (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, start_ip TEXT NOT NULL,
                start_int INTEGER NOT NULL, max_int INTEGER, routing_domain TEXT NOT NULL DEFAULT 'default',
                reserve_percent INTEGER NOT NULL DEFAULT 25, description TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(routing_domain,start_int)
            )");
            $pdo->exec('INSERT INTO address_spaces_v4 SELECT id,name,start_ip,start_int,max_int,routing_domain,reserve_percent,description,created_at FROM address_spaces');
            $pdo->exec("CREATE TABLE network_nodes_v4 (
                id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
                address_space_id INTEGER NOT NULL REFERENCES address_spaces(id) ON DELETE RESTRICT,
                parent_id INTEGER REFERENCES network_nodes_v4(id) ON DELETE RESTRICT,
                site_id INTEGER REFERENCES sites(id) ON DELETE RESTRICT,
                name TEXT NOT NULL, node_type TEXT NOT NULL CHECK (node_type IN ('container','leaf')),
                cidr TEXT NOT NULL, network_int INTEGER NOT NULL, broadcast_int INTEGER NOT NULL,
                prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 32), requested_hosts INTEGER NOT NULL DEFAULT 0,
                reserve_percent INTEGER NOT NULL DEFAULT 25, gateway_int INTEGER, vlan_id INTEGER,
                vrf_name TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', sort_order INTEGER NOT NULL DEFAULT 0,
                UNIQUE(parent_id,name)
            )");
            $pdo->exec('INSERT INTO network_nodes_v4 SELECT id,address_space_id,parent_id,site_id,name,node_type,cidr,network_int,broadcast_int,prefix,requested_hosts,reserve_percent,gateway_int,vlan_id,vrf_name,description,sort_order FROM network_nodes');
            $pdo->exec("CREATE TABLE network_interfaces_v4 (
                id INTEGER PRIMARY KEY AUTOINCREMENT, host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
                network_id INTEGER NOT NULL REFERENCES network_nodes_v4(id) ON DELETE RESTRICT, name TEXT NOT NULL,
                mac_address TEXT, ip_int INTEGER NOT NULL, dns_name TEXT NOT NULL DEFAULT '', UNIQUE(host_id,name)
            )");
            $pdo->exec('INSERT INTO network_interfaces_v4 SELECT * FROM network_interfaces');
            $pdo->exec("CREATE TABLE network_reservations_v4 (
                id INTEGER PRIMARY KEY AUTOINCREMENT, network_id INTEGER NOT NULL REFERENCES network_nodes_v4(id) ON DELETE RESTRICT,
                ip_int INTEGER NOT NULL, label TEXT NOT NULL, reason TEXT NOT NULL DEFAULT ''
            )");
            $pdo->exec('INSERT INTO network_reservations_v4 SELECT * FROM network_reservations');
            $pdo->exec('DROP TABLE network_interfaces');$pdo->exec('DROP TABLE network_reservations');$pdo->exec('DROP TABLE network_nodes');$pdo->exec('DROP TABLE address_spaces');
            $pdo->exec('ALTER TABLE address_spaces_v4 RENAME TO address_spaces');
            $pdo->exec('ALTER TABLE network_nodes_v4 RENAME TO network_nodes');$pdo->exec('ALTER TABLE network_interfaces_v4 RENAME TO network_interfaces');$pdo->exec('ALTER TABLE network_reservations_v4 RENAME TO network_reservations');
            $pdo->exec('CREATE INDEX idx_network_tree ON network_nodes(address_space_id,parent_id,network_int)');$pdo->exec('CREATE INDEX idx_network_interfaces_node ON network_interfaces(network_id)');$pdo->exec('CREATE INDEX idx_network_reservations_node ON network_reservations(network_id)');
        } finally { $pdo->exec('PRAGMA foreign_keys=ON'); }
        if($pdo->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Migration der VRF-fähigen IP-Eindeutigkeit ist inkonsistent.');
    }
}
