<?php
declare(strict_types=1);

namespace IpDesigner;

use InvalidArgumentException;

final class IpMath
{
    public static function toInt(string $ip): int
    {
        $value = ip2long(trim($ip));
        if ($value === false || filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Ungültige IPv4-Adresse.');
        }
        return (int) sprintf('%u', $value);
    }

    public static function toIp(int $value): string
    {
        if ($value < 0 || $value > 4294967295) {
            throw new InvalidArgumentException('IPv4-Zahlenwert außerhalb des gültigen Bereichs.');
        }
        return long2ip($value);
    }

    /** @return array{cidr:string,network:int,broadcast:int,prefix:int,size:int,usable:int,first_usable:int,last_usable:int} */
    public static function parseCidr(string $cidr): array
    {
        if (!preg_match('/^([^\/]+)\/(\d{1,2})$/', trim($cidr), $matches)) {
            throw new InvalidArgumentException('CIDR muss im Format 192.0.2.0/24 angegeben werden.');
        }
        $ip = self::toInt($matches[1]);
        $prefix = (int) $matches[2];
        if ($prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('Präfix muss zwischen /0 und /32 liegen.');
        }
        $size = 2 ** (32 - $prefix);
        $network = intdiv($ip, $size) * $size;
        if ($network !== $ip) {
            throw new InvalidArgumentException('CIDR ist nicht auf die Netzwerkadresse ausgerichtet: ' . self::toIp($network) . "/$prefix");
        }
        $broadcast = $network + $size - 1;
        $usable = $prefix <= 30 ? max(0, $size - 2) : $size;
        $first = $prefix <= 30 ? $network + 1 : $network;
        $last = $prefix <= 30 ? $broadcast - 1 : $broadcast;
        return [
            'cidr' => self::toIp($network) . "/$prefix",
            'network' => $network,
            'broadcast' => $broadcast,
            'prefix' => $prefix,
            'size' => $size,
            'usable' => $usable,
            'first_usable' => $first,
            'last_usable' => $last,
        ];
    }

    public static function prefixForHosts(int $hosts): int
    {
        if ($hosts < 1) {
            throw new InvalidArgumentException('Hostanzahl muss größer als null sein.');
        }
        if ($hosts === 1) return 32;
        if ($hosts === 2) return 31;
        for ($prefix = 30; $prefix >= 0; $prefix--) {
            if ((2 ** (32 - $prefix)) - 2 >= $hosts) return $prefix;
        }
        throw new InvalidArgumentException('Hostanzahl ist für IPv4 zu groß.');
    }

    public static function isUsable(int $ip, array $network): bool
    {
        return $ip >= $network['first_usable'] && $ip <= $network['last_usable'];
    }

    /** @return list<string> */
    public static function rangeToCidrs(int $start,int $end):array
    {
        if($start<0||$end>4294967295||$start>$end)throw new InvalidArgumentException('Ungültiger IPv4-Adressbereich.');
        $cidrs=[];
        for($cursor=$start;$cursor<=$end;){
            $prefix=32;
            while($prefix>0){$candidateSize=2**(33-$prefix);if($cursor%$candidateSize!==0||$cursor+$candidateSize-1>$end)break;$prefix--;}
            $cidrs[]=self::toIp($cursor).'/'.$prefix;
            $cursor+=2**(32-$prefix);
        }
        return$cidrs;
    }
}
