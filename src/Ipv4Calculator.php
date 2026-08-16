<?php
declare(strict_types=1);

namespace IpDesigner;

use InvalidArgumentException;

final class Ipv4Calculator
{
    public function calculate(array $input): array
    {
        return match ((string)($input['mode'] ?? '')) {
            'analyze' => $this->analyze((string)($input['cidr'] ?? '')),
            'hosts' => $this->forHosts($input),
            'range' => $this->forRange((string)($input['start_ip'] ?? ''), (string)($input['end_ip'] ?? '')),
            'split' => $this->split((string)($input['cidr'] ?? ''), $input['target_prefix'] ?? null),
            default => throw new InvalidArgumentException('Unbekannter IPv4-Rechenmodus.'),
        };
    }

    private function analyze(string $input): array
    {
        if (!preg_match('/^([^\/]+)\/(\d{1,2})$/', trim($input), $matches)) {
            throw new InvalidArgumentException('IPv4 und Präfix müssen beispielsweise als 192.168.10.25/24 angegeben werden.');
        }
        $ip = IpMath::toInt($matches[1]);
        $prefix = (int)$matches[2];
        if ($prefix < 0 || $prefix > 32) throw new InvalidArgumentException('Präfix muss zwischen /0 und /32 liegen.');
        $size = 2 ** (32 - $prefix);
        $network = intdiv($ip, $size) * $size;
        $parsed = IpMath::parseCidr(IpMath::toIp($network).'/'.$prefix);
        $mask = $prefix === 0 ? 0 : 4294967295 - ($size - 1);
        return [
            'mode' => 'analyze',
            'input_ip' => IpMath::toIp($ip),
            'cidr' => $parsed['cidr'],
            'prefix' => $prefix,
            'network' => IpMath::toIp($parsed['network']),
            'broadcast' => IpMath::toIp($parsed['broadcast']),
            'netmask' => IpMath::toIp($mask),
            'wildcard' => IpMath::toIp(4294967295 - $mask),
            'first_usable' => IpMath::toIp($parsed['first_usable']),
            'last_usable' => IpMath::toIp($parsed['last_usable']),
            'size' => $parsed['size'],
            'usable' => $parsed['usable'],
            'normalized' => $ip !== $network,
        ];
    }

    private function forHosts(array $input): array
    {
        $hosts = filter_var($input['hosts'] ?? null, FILTER_VALIDATE_INT);
        $reserve = filter_var($input['reserve_percent'] ?? 0, FILTER_VALIDATE_INT);
        if ($hosts === false || $hosts < 1) throw new InvalidArgumentException('Hostbedarf muss eine positive ganze Zahl sein.');
        if ($reserve === false || $reserve < 0 || $reserve > 500) throw new InvalidArgumentException('Reserve muss zwischen 0 und 500 Prozent liegen.');
        $planned = (int)ceil($hosts * (1 + $reserve / 100));
        $prefix = IpMath::prefixForHosts($planned);
        $network = IpMath::parseCidr('0.0.0.0/'.$prefix);
        return [
            'mode' => 'hosts',
            'hosts' => $hosts,
            'reserve_percent' => $reserve,
            'planned_hosts' => $planned,
            'prefix' => $prefix,
            'prefix_label' => '/'.$prefix,
            'size' => $network['size'],
            'usable' => $network['usable'],
            'remaining' => $network['usable'] - $planned,
        ];
    }

    private function forRange(string $startInput, string $endInput): array
    {
        $start = IpMath::toInt($startInput);
        $end = IpMath::toInt($endInput);
        if ($start > $end) throw new InvalidArgumentException('Die Start-IP muss vor oder gleich der End-IP liegen.');
        $cidrs = IpMath::rangeToCidrs($start,$end);
        $different = $start ^ $end;
        $coverPrefix = 32;
        while ($different > 0) { $different = intdiv($different, 2); $coverPrefix--; }
        $coverSize = 2 ** (32 - $coverPrefix);
        $coverStart = intdiv($start, $coverSize) * $coverSize;
        $cover = IpMath::parseCidr(IpMath::toIp($coverStart).'/'.$coverPrefix);
        return [
            'mode' => 'range',
            'start_ip' => IpMath::toIp($start),
            'end_ip' => IpMath::toIp($end),
            'size' => $end - $start + 1,
            'exact_cidrs' => $cidrs,
            'cover_cidr' => $cover['cidr'],
            'cover_extra' => $cover['size'] - ($end - $start + 1),
        ];
    }

    private function split(string $cidr, mixed $targetInput): array
    {
        $network = IpMath::parseCidr(trim($cidr));
        $target = filter_var($targetInput, FILTER_VALIDATE_INT);
        if ($target === false || $target <= $network['prefix'] || $target > 32) {
            throw new InvalidArgumentException('Der Zielpräfix muss größer als der Ausgangspräfix und höchstens /32 sein.');
        }
        $count = 2 ** ($target - $network['prefix']);
        if ($count > 4096) throw new InvalidArgumentException('Die Aufteilung würde mehr als 4096 Teilnetze erzeugen. Bitte einen gröberen Zielpräfix wählen.');
        $childSize = 2 ** (32 - $target);
        $children = [];
        for ($index = 0; $index < $count; $index++) {
            $child = IpMath::parseCidr(IpMath::toIp($network['network'] + $index * $childSize).'/'.$target);
            $children[] = ['cidr'=>$child['cidr'],'network'=>IpMath::toIp($child['network']),'broadcast'=>IpMath::toIp($child['broadcast']),'usable'=>$child['usable']];
        }
        return ['mode'=>'split','source_cidr'=>$network['cidr'],'target_prefix'=>$target,'count'=>$count,'children'=>$children];
    }
}
