<?php
declare(strict_types=1);

namespace IpDesigner;

use InvalidArgumentException;

/** UTF-8 CSV with explicit limits and reversible spreadsheet-formula protection. */
final class Csv
{
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 500;

    public static function encode(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $columns, ';', '"', '', "\r\n");
            foreach ($rows as $row) {
                $values = array_map(static function ($column) use ($row): string {
                    $value = (string)($row[$column] ?? '');
                    return self::needsProtection($value) ? "'".$value : $value;
                }, $columns);
                fputcsv($stream, $values, ';', '"', '', "\r\n");
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    public static function decode(string $csv, array $allowed, array $required): array
    {
        if (strlen($csv) > self::MAX_BYTES) throw new InvalidArgumentException('CSV ist zu groß (maximal 512 KiB).');
        if (!mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) throw new InvalidArgumentException('Bitte eine UTF-8-CSV ohne Nullzeichen verwenden.');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $delimiter = null;
        if (preg_match('/^sep=([;,\t])\r?\n/i', $csv, $match)) {
            $delimiter = $match[1]; $csv = substr($csv, strlen($match[0]));
        }
        $headerLine = substr($csv, 0, strcspn($csv, "\r\n"));
        if ($delimiter === null) {
            $best = 0;
            foreach ([';', ',', "\t"] as $candidate) {
                $count = count(str_getcsv($headerLine, $candidate, '"', ''));
                if ($count > $best) { $best = $count; $delimiter = $candidate; }
            }
        }
        self::validateQuotes($csv, $delimiter);
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, $csv); rewind($stream);
            $header = fgetcsv($stream, null, $delimiter, '"', '');
            if (!$header) throw new InvalidArgumentException('CSV-Kopfzeile fehlt.');
            $header = array_map(static fn($value) => strtolower(trim((string)$value)), $header);
            if (count($header) !== count(array_unique($header))) throw new InvalidArgumentException('CSV enthält doppelte Spalten.');
            $unknown = array_diff($header, $allowed); $missing = array_diff($required, $header);
            if ($unknown) throw new InvalidArgumentException('Unbekannte CSV-Spalten: '.implode(', ', $unknown));
            if ($missing) throw new InvalidArgumentException('Erforderliche CSV-Spalten fehlen: '.implode(', ', $missing));
            $rows = []; $record = 1;
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $record++;
                if ($values === [null] || !array_filter($values, static fn($v) => trim((string)$v) !== '')) continue;
                if (count($values) !== count($header)) throw new InvalidArgumentException('CSV-Datensatz '.$record.': Spaltenanzahl stimmt nicht mit der Kopfzeile überein.');
                $values = array_map(static function ($value): string {
                    $value = (string)$value;
                    if (str_starts_with($value, "'") && self::needsProtection(substr($value, 1))) $value = substr($value, 1);
                    return trim($value);
                }, $values);
                $rows[] = ['record'=>$record, 'values'=>array_combine($header, $values)];
                if (count($rows) > self::MAX_ROWS) throw new InvalidArgumentException('Maximal 500 CSV-Datensätze pro Import.');
            }
            if (!$rows) throw new InvalidArgumentException('CSV enthält keine Datensätze.');
            return $rows;
        } finally { fclose($stream); }
    }

    private static function needsProtection(string $value): bool
    {
        return preg_match('/^(?:[\s]*[=+@\-]|[\t\r\n\x27])/u', $value) === 1;
    }

    private static function validateQuotes(string $csv, string $delimiter): void
    {
        $quoted = false; $closed = false; $start = true;
        for ($i = 0, $length = strlen($csv); $i < $length; $i++) {
            $char = $csv[$i];
            if ($quoted) {
                if ($char === '"') {
                    if (($csv[$i + 1] ?? '') === '"') $i++;
                    else { $quoted = false; $closed = true; }
                }
                continue;
            }
            if ($char === $delimiter || $char === "\r" || $char === "\n") { $start = true; $closed = false; continue; }
            if ($closed || ($char === '"' && !$start)) throw new InvalidArgumentException('Ungültige CSV-Anführungszeichen. Textfelder mit Anführungszeichen vollständig quotieren.');
            if ($char === '"') $quoted = true;
            $start = false;
        }
        if ($quoted) throw new InvalidArgumentException('Nicht geschlossenes CSV-Textfeld.');
    }
}
