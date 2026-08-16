PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK (kind IN ('address_space','site','block','subnet','host','service','virtual_network')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    address_space_id INTEGER REFERENCES address_spaces(id) ON DELETE RESTRICT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL UNIQUE,
    domain_name TEXT NOT NULL DEFAULT '',
    allocation_mode TEXT NOT NULL DEFAULT 'range' CHECK (allocation_mode IN ('range','cidr')),
    location TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS address_blocks (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    cidr TEXT NOT NULL UNIQUE,
    network_int INTEGER NOT NULL,
    broadcast_int INTEGER NOT NULL,
    prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 32),
    description TEXT NOT NULL DEFAULT '',
    UNIQUE(site_id, name)
);
CREATE INDEX IF NOT EXISTS idx_blocks_range ON address_blocks(network_int, broadcast_int);

CREATE TABLE IF NOT EXISTS subnets (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    block_id INTEGER NOT NULL REFERENCES address_blocks(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    cidr TEXT NOT NULL UNIQUE,
    network_int INTEGER NOT NULL,
    broadcast_int INTEGER NOT NULL,
    prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 32),
    gateway_int INTEGER,
    vlan_id INTEGER CHECK (vlan_id IS NULL OR vlan_id BETWEEN 1 AND 4094),
    vrf_name TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    UNIQUE(block_id, name)
);
CREATE INDEX IF NOT EXISTS idx_subnets_range ON subnets(network_int, broadcast_int);

CREATE TABLE IF NOT EXISTS hosts (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    hostname TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT 'server' CHECK (type IN ('server','vm','network','client','appliance','other')),
    status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','degraded','inactive')),
    description TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    is_router INTEGER NOT NULL DEFAULT 0 CHECK (is_router IN (0,1))
);

CREATE TABLE IF NOT EXISTS interfaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
    subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    mac_address TEXT,
    ip_int INTEGER NOT NULL UNIQUE,
    dns_name TEXT NOT NULL DEFAULT '',
    UNIQUE(host_id, name)
);
CREATE INDEX IF NOT EXISTS idx_interfaces_subnet ON interfaces(subnet_id);

CREATE TABLE IF NOT EXISTS dns_names (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
    interface_id INTEGER REFERENCES interfaces(id) ON DELETE CASCADE,
    kind TEXT NOT NULL CHECK (kind IN ('primary','host_alias','interface_alias')),
    input_name TEXT NOT NULL,
    is_fqdn INTEGER NOT NULL DEFAULT 0 CHECK (is_fqdn IN (0,1)),
    fqdn TEXT NOT NULL UNIQUE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_dns_primary_host ON dns_names(host_id) WHERE kind='primary';
CREATE INDEX IF NOT EXISTS idx_dns_host ON dns_names(host_id, kind);

CREATE TABLE IF NOT EXISTS address_spaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id INTEGER UNIQUE REFERENCES entities(id) ON DELETE CASCADE,
    name TEXT NOT NULL UNIQUE,
    start_ip TEXT NOT NULL,
    start_int INTEGER NOT NULL,
    max_int INTEGER,
    routing_domain TEXT NOT NULL DEFAULT 'default',
    reserve_percent INTEGER NOT NULL DEFAULT 25 CHECK (reserve_percent BETWEEN 0 AND 500),
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(routing_domain,start_int)
);

CREATE TABLE IF NOT EXISTS network_nodes (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    address_space_id INTEGER NOT NULL REFERENCES address_spaces(id) ON DELETE RESTRICT,
    parent_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT,
    site_id INTEGER REFERENCES sites(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    node_type TEXT NOT NULL CHECK (node_type IN ('container','leaf')),
    cidr TEXT NOT NULL,
    network_int INTEGER NOT NULL,
    broadcast_int INTEGER NOT NULL,
    prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 32),
    manual_prefix INTEGER CHECK (manual_prefix IS NULL OR manual_prefix BETWEEN 0 AND 31),
    manual_start_int INTEGER CHECK (manual_start_int IS NULL OR manual_start_int BETWEEN 0 AND 4294967295),
    l3_enabled INTEGER NOT NULL DEFAULT 0 CHECK (l3_enabled IN (0,1)),
    allocation_type TEXT NOT NULL DEFAULT 'subnet' CHECK (allocation_type IN ('subnet','static_pool','dhcp_pool')),
    pool_start_offset INTEGER,
    pool_end_offset INTEGER,
    pool_range_fixed INTEGER NOT NULL DEFAULT 0 CHECK (pool_range_fixed IN (0,1)),
    requested_hosts INTEGER NOT NULL DEFAULT 0 CHECK (requested_hosts >= 0),
    reserve_percent INTEGER NOT NULL DEFAULT 25 CHECK (reserve_percent BETWEEN 0 AND 500),
    gateway_int INTEGER,
    vlan_id INTEGER CHECK (vlan_id IS NULL OR vlan_id BETWEEN 1 AND 4094),
    vrf_name TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    description TEXT NOT NULL DEFAULT '',
    UNIQUE(parent_id,name)
);
CREATE INDEX IF NOT EXISTS idx_network_tree ON network_nodes(address_space_id,parent_id,network_int);

CREATE TABLE IF NOT EXISTS replan_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address_space_id INTEGER NOT NULL REFERENCES address_spaces(id) ON DELETE CASCADE,
    revision_before TEXT NOT NULL,
    revision_after TEXT NOT NULL,
    before_snapshot TEXT NOT NULL,
    after_snapshot TEXT NOT NULL,
    warnings TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_replan_space ON replan_runs(address_space_id,id DESC);

CREATE TABLE IF NOT EXISTS network_interfaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
    network_id INTEGER NOT NULL REFERENCES network_nodes(id) ON DELETE RESTRICT,
    pool_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    mac_address TEXT,
    ip_int INTEGER NOT NULL,
    dns_name TEXT NOT NULL DEFAULT '',
    UNIQUE(host_id,name)
);
CREATE INDEX IF NOT EXISTS idx_network_interfaces_node ON network_interfaces(network_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_network_interfaces_ip_global ON network_interfaces(ip_int);

CREATE TABLE IF NOT EXISTS network_interface_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    interface_id INTEGER NOT NULL REFERENCES network_interfaces(id) ON DELETE CASCADE,
    ip_int INTEGER NOT NULL UNIQUE,
    UNIQUE(interface_id,ip_int)
);
CREATE INDEX IF NOT EXISTS idx_network_interface_addresses_interface ON network_interface_addresses(interface_id);

CREATE TABLE IF NOT EXISTS network_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    network_id INTEGER NOT NULL REFERENCES network_nodes(id) ON DELETE RESTRICT,
    pool_id INTEGER REFERENCES network_nodes(id) ON DELETE RESTRICT,
    reservation_type TEXT NOT NULL DEFAULT 'static' CHECK (reservation_type IN ('static','dhcp_reservation','dhcp_exclusion')),
    ip_int INTEGER NOT NULL,
    label TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_network_reservations_node ON network_reservations(network_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_network_reservations_ip_global ON network_reservations(ip_int);

CREATE TABLE IF NOT EXISTS gateway_bindings (
    network_id INTEGER PRIMARY KEY REFERENCES network_nodes(id) ON DELETE CASCADE,
    interface_id INTEGER NOT NULL UNIQUE REFERENCES network_interfaces(id) ON DELETE RESTRICT,
    address_offset INTEGER NOT NULL CHECK (address_offset > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS static_routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
    destination_cidr TEXT NOT NULL,
    destination_network_id INTEGER REFERENCES network_nodes(id) ON DELETE SET NULL,
    egress_interface_id INTEGER NOT NULL REFERENCES network_interfaces(id) ON DELETE RESTRICT,
    next_hop_int INTEGER NOT NULL,
    metric INTEGER NOT NULL DEFAULT 10 CHECK (metric BETWEEN 0 AND 65535),
    description TEXT NOT NULL DEFAULT '',
    UNIQUE(router_id,destination_cidr,egress_interface_id,next_hop_int)
);
CREATE INDEX IF NOT EXISTS idx_static_routes_router ON static_routes(router_id,destination_cidr);

CREATE TABLE IF NOT EXISTS virtual_networks (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    network_type TEXT NOT NULL CHECK (network_type IN ('vpn','docker_bridge')),
    name TEXT NOT NULL UNIQUE,
    cidr TEXT NOT NULL,
    network_int INTEGER NOT NULL,
    broadcast_int INTEGER NOT NULL,
    prefix INTEGER NOT NULL CHECK (prefix BETWEEN 0 AND 31),
    gateway_int INTEGER,
    owner_host_id INTEGER REFERENCES hosts(id) ON DELETE RESTRICT,
    driver TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_virtual_network_range ON virtual_networks(network_int,broadcast_int);
CREATE INDEX IF NOT EXISTS idx_virtual_network_owner ON virtual_networks(owner_host_id);

CREATE TABLE IF NOT EXISTS vpn_interfaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    virtual_network_id INTEGER NOT NULL REFERENCES virtual_networks(id) ON DELETE CASCADE,
    router_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    ip_int INTEGER NOT NULL UNIQUE,
    UNIQUE(virtual_network_id,router_id)
);
CREATE INDEX IF NOT EXISTS idx_vpn_interfaces_network ON vpn_interfaces(virtual_network_id,ip_int);

CREATE TABLE IF NOT EXISTS vpn_interface_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    interface_id INTEGER NOT NULL REFERENCES vpn_interfaces(id) ON DELETE CASCADE,
    ip_int INTEGER NOT NULL UNIQUE,
    UNIQUE(interface_id,ip_int)
);
CREATE INDEX IF NOT EXISTS idx_vpn_interface_addresses_interface ON vpn_interface_addresses(interface_id);

CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY REFERENCES entities(id) ON DELETE CASCADE,
    host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    protocol TEXT NOT NULL DEFAULT 'tcp' CHECK (protocol IN ('tcp','udp','icmp','other')),
    port_start INTEGER CHECK (port_start IS NULL OR port_start BETWEEN 1 AND 65535),
    port_end INTEGER CHECK (port_end IS NULL OR port_end BETWEEN 1 AND 65535),
    url TEXT NOT NULL DEFAULT '',
    environment TEXT NOT NULL DEFAULT 'production' CHECK (environment IN ('production','test','development','other')),
    status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','degraded','inactive')),
    description TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    UNIQUE(host_id, name, protocol, port_start)
);

CREATE TABLE IF NOT EXISTS reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE RESTRICT,
    ip_int INTEGER NOT NULL UNIQUE,
    label TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_reservations_subnet ON reservations(subnet_id);

CREATE TABLE IF NOT EXISTS relations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    target_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('connected','routes_to','uses','depends_on','manages')),
    label TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    CHECK (source_id <> target_id),
    UNIQUE(source_id, target_id, type)
);

CREATE TABLE IF NOT EXISTS topology_positions (
    entity_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    scope TEXT NOT NULL DEFAULT 'global',
    x REAL NOT NULL,
    y REAL NOT NULL,
    PRIMARY KEY(entity_id, scope)
);

INSERT OR IGNORE INTO schema_migrations(version) VALUES (1);
