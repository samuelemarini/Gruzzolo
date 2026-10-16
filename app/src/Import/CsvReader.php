<?php

namespace App\Import;

/** Legge un CSV qualsiasi: toglie il BOM, indovina il separatore, restituisce righe per nome di colonna. */
final class CsvReader
{
    /** @return list<string> */
    public static function header(string $path): array
    {
        $handle = self::open($path, $delimiter);
        $header = fgetcsv($handle, null, $delimiter, '"', '') ?: [];
        fclose($handle);

        return array_map(fn ($h) => trim((string) $h), $header);
    }

    /**
     * @return \Generator<int, array<string, string>|string> numero di riga => colonne, oppure il messaggio d'errore
     */
    public static function rows(string $path): \Generator
    {
        $handle = self::open($path, $delimiter);
        $header = array_map(fn ($h) => trim((string) $h), fgetcsv($handle, null, $delimiter, '"', '') ?: []);
        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$line;
            if ($cells === [null] || $cells === ['']) {
                continue;
            }
            if (count($cells) !== count($header)) {
                yield $line => sprintf('ha %d colonne invece di %d (riga incompleta?)', count($cells), count($header));
                continue;
            }
            yield $line => array_combine($header, array_map(fn ($c) => (string) $c, $cells));
        }
        fclose($handle);
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
