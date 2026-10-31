<?php

namespace App\Import;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Legge un CSV qualsiasi: toglie il BOM, indovina il separatore, restituisce righe per nome di colonna.
 * Le colonne con lo stesso nome diventano "Nome", "Nome (2)", ...; le celle non UTF-8 si leggono come Windows-1252 o Mac Roman.
 */
final class CsvReader
{
    /** @return list<string> */
    public static function header(string $path): array
    {
        $handle = self::open($path, $delimiter);
        $header = fgetcsv($handle, null, $delimiter, '"', '') ?: [];
        fclose($handle);

        return self::names($header);
    }

    /**
     * @return \Generator<int, array<string, string>|TranslatableMessage> numero di riga => colonne, oppure il messaggio d'errore
     */
    public static function rows(string $path): \Generator
    {
        $handle = self::open($path, $delimiter);
        $header = self::names(fgetcsv($handle, null, $delimiter, '"', '') ?: []);
        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$line;
            if ($cells === [null] || $cells === ['']) {
                continue;
            }
            if (count($cells) !== count($header)) {
                yield $line => new TranslatableMessage('ha %found% colonne invece di %expected% (riga incompleta?)', ['%found%' => count($cells), '%expected%' => count($header)]);
                continue;
            }
            yield $line => array_combine($header, array_map(self::utf8(...), $cells));
        }
        fclose($handle);
    }

    /** @return list<string> nomi delle colonne, resi unici */
    private static function names(array $header): array
    {
        $seen = [];
        $out = [];
        foreach ($header as $h) {
            $name = self::utf8(trim((string) $h));
            $n = $seen[mb_strtolower($name)] = ($seen[mb_strtolower($name)] ?? 0) + 1;
            $out[] = $n === 1 ? $name : sprintf('%s (%d)', $name, $n);
        }

        return $out;
    }

    /**
     * Celle non UTF-8: Windows-1252 se ci sono lettere accentate in 0xC0-0xFF, altrimenti Mac Roman se ci
     * sono byte 0x80-0x9F o virgolette 0xD0-0xD5 (gli export da Mac: "Caff\x8F" = Caffè).
     */
    private static function utf8(?string $value): string
    {
        $value = (string) $value;
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $macRoman = !preg_match('/[\xC0-\xCF\xD6-\xFF]/', $value) && preg_match('/[\x80-\x9F\xD0-\xD5]/', $value);
        $converted = $macRoman ? @iconv('MACINTOSH', 'UTF-8//IGNORE', $value) : false;

        return $converted !== false ? $converted : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /** @return resource */
    private static function open(string $path, ?string &$delimiter)
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('File non leggibile');
        }
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }
}
