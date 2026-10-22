<?php
declare(strict_types=1);

namespace IpDesigner;

use PDO;

/** Capacity counts addresses, not devices. Pool boundaries belong to their L3 network. */
final class NetworkCapacity
{
    public static function isPool(array $node): bool
    {
        return in_array($node['allocation_type'] ?? '', ['static_pool', 'dhcp_pool'], true);
    }

    public static function isL3(array $node): bool
    {
        return !empty($node['l3_enabled']) || ($node['node_type'] === 'leaf' && !self::isPool($node));
    }

    public static function l3Id(array $node, array $nodes): ?int
    {
        $seen = [];
        while (true) {
            $id = (int)$node['id'];
            if (isset($seen[$id])) return null;
            $seen[$id] = true;
            if (self::isL3($node)) return $id;
            $node = $nodes[(int)($node['parent_id'] ?? 0)] ?? null;
            if (!$node) return null;
        }
    }

    public static function poolRange(array $node): array
    {
        $start = (int)$node['network_int'];
        return [$start + (int)($node['pool_start_offset'] ?? 0),
            $start + (int)($node['pool_end_offset'] ?? ((int)$node['broadcast_int'] - $start))];
    }

    /** One bounded set of queries, independent of the number of networks. */
    public static function load(PDO $db): array
    {
        $nodes = array_column($db->query('SELECT * FROM network_nodes')->fetchAll(), null, 'id');
        $addresses = $db->query('SELECT network_id,pool_id,ip_int FROM network_interfaces UNION ALL SELECT i.network_id,i.pool_id,a.ip_int FROM network_interface_addresses a JOIN network_interfaces i ON i.id=a.interface_id')->fetchAll();
        $reservations = $db->query('SELECT network_id,pool_id,ip_int FROM network_reservations')->fetchAll();
        return self::calculate($nodes, $addresses, $reservations);
    }

    public static function calculate(array $nodes, array $addresses, array $reservations): array
    {
        $assigned = $reserved = [];
        foreach (['assigned' => $addresses, 'reserved' => $reservations] as $kind => $rows) {
            foreach ($rows as $row) {
                foreach (array_unique([(int)$row['network_id'], (int)($row['pool_id'] ?? 0)]) as $id) {
                    if (!$id) continue;
                    if ($kind === 'assigned') $assigned[$id][(int)$row['ip_int']] = true;
                    else $reserved[$id][(int)$row['ip_int']] = true;
                }
            }
        }
        $result = [];
        foreach ($nodes as $id => $node) {
            if (!self::isL3($node) && !self::isPool($node)) continue;
            $l3Id = self::l3Id($node, $nodes);
            if ($l3Id === null) continue;
            try { $net = IpMath::parseCidr($nodes[$l3Id]['cidr']); }
            catch (\InvalidArgumentException) { continue; }
            [$start, $end] = self::isPool($node) ? self::poolRange($node) : [$net['first_usable'], $net['last_usable']];
            $start = max($start, $net['first_usable']);
            $end = min($end, $net['last_usable']);
            $gateway = $nodes[$l3Id]['gateway_int'] === null ? null : (int)$nodes[$l3Id]['gateway_int'];
            $gatewayInside = $gateway !== null && $gateway >= $start && $gateway <= $end;
            $capacity = max(0, $end - $start + 1 - (int)$gatewayInside);
            $valid = static fn($ip) => $ip >= $start && $ip <= $end && $ip !== $gateway;
            $used = array_filter($assigned[$id] ?? [], $valid, ARRAY_FILTER_USE_KEY);
            $held = array_filter($reserved[$id] ?? [], $valid, ARRAY_FILTER_USE_KEY);
            $occupied = count($used + $held);
            $result[$id] = ['host_capacity' => $capacity, 'occupied_count' => $occupied,
                'assigned_count' => count($used), 'reserved_count' => count($held),
                'free_count' => max(0, $capacity - $occupied),
                'utilization' => $capacity ? round(100 * $occupied / $capacity, 2) : 0];
        }
        return $result;
    }
}
