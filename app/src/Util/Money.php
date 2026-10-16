<?php

namespace App\Util;

final class Money
{
    /**
     * Interpreta un importo scritto a mano: "1.247,80", "1247.8", "12,5", "€ 30".
     * Restituisce la stringa decimale per Doctrine ("1247.80") o null se non e' un numero.
     */
    public static function parse(mixed $value, int $scale = 2): ?string
    {
        $value = trim(str_replace(['€', ' ', "\u{00A0}", "'"], '', (string) $value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',')) {
            // Formato italiano: il punto separa le migliaia, la virgola i decimali.
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
