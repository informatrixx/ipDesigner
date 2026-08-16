<?php
declare(strict_types=1);

namespace IpDesigner;

use InvalidArgumentException;

final class DnsName
{
    public static function domain(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if ($value === '') throw new InvalidArgumentException('Domainname ist erforderlich.');
        $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253) throw new InvalidArgumentException('Domainname ist ungültig.');
        $labels = explode('.', strtolower($ascii));
        if (count($labels) < 2) throw new InvalidArgumentException('Domainname muss mindestens einen Punkt enthalten.');
        foreach ($labels as $label) self::assertLabel($label, 'Domainname');
        return implode('.', $labels);
    }

    public static function hostname(string $value): string
    {
        $value = strtolower(trim($value));
        self::assertLabel($value, 'Hostname');
        return $value;
    }

    /** @return array{hostname:string,input_name:string,is_fqdn:int,fqdn:string} */
    public static function primary(string $value, string $siteDomain): array
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException('Hostname ist erforderlich.');
        if (str_ends_with($value, '.')) {
            $fqdn = self::domain($value);
            $absolute = $fqdn . '.';
            return ['hostname'=>$absolute, 'input_name'=>$absolute, 'is_fqdn'=>1, 'fqdn'=>$fqdn];
        }
        $short = self::hostname($value);
        return ['hostname'=>$short, 'input_name'=>$short, 'is_fqdn'=>0, 'fqdn'=>$short . '.' . self::domain($siteDomain)];
    }

    /** @return array{input_name:string,is_fqdn:int,fqdn:string} */
    public static function alias(string $value, string $siteDomain): array
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if ($value === '') throw new InvalidArgumentException('DNS-Alias darf nicht leer sein.');
        if (str_contains($value, '.')) {
            $fqdn = self::domain($value);
            return ['input_name'=>$fqdn, 'is_fqdn'=>1, 'fqdn'=>$fqdn];
        }
        $short = self::hostname($value);
        return ['input_name'=>$short, 'is_fqdn'=>0, 'fqdn'=>$short . '.' . self::domain($siteDomain)];
    }

    public static function fqdn(string $hostname, string $domain): string
    {
        return self::hostname($hostname) . '.' . self::domain($domain);
    }

    private static function assertLabel(string $label, string $field): void
    {
        if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
            throw new InvalidArgumentException("$field enthält ein ungültiges DNS-Label.");
        }
    }
}
