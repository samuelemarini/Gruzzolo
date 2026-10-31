<?php

namespace App\Util;

use App\Service\Language;

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

    /**
     * Importo scritto dall'utente nella lingua dell'interfaccia: "1.247,80" in italiano, "1,247.80" in
     * inglese. L'altro separatore da solo e senza tre cifre dopo vale come decimale ("12.5" in italiano,
     * "12,5" in inglese). Per i file importati, che hanno un formato loro, resta parse().
     */
    public static function parseLocal(mixed $value, int $scale = 2): ?string
    {
        [$decimal] = Language::separators();
        $value = trim(str_replace(['€', ' ', "\u{00A0}", "\u{202F}", "'"], '', (string) $value));
        if ($value === '') {
            return null;
        }
        // Con tutti e due i separatori ("1,247.80" copiato da altrove) i decimali sono dopo l'ultimo
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
        }
        $other = $decimal === ',' ? '.' : ',';
        // Con tre decimali (prezzo al litro: "1.849") le tre cifre dopo il separatore non sono migliaia
        $thousands = $scale < 3 && preg_match('/'.preg_quote($other, '/').'\d{3}$/', $value);
        if (!str_contains($value, $decimal) && substr_count($value, $other) === 1 && !$thousands) {
            $value = str_replace($other, $decimal, $value);
        }
        $value = str_replace([$other, $decimal], ['', '.'], $value);
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
