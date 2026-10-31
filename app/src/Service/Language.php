<?php

namespace App\Service;

/**
 * Lingue dell'interfaccia e i loro separatori numerici. La lingua della richiesta la sceglie
 * LocaleSubscriber (utente o browser) e finisce in \Locale::getDefault(): da li' la leggono
 * i filtri Twig e Money::parseLocal.
 */
final class Language
{
    public const DEFAULT = 'it';

    /** Nome di ogni lingua nella lingua stessa, come si mostra nella scelta. */
    public const LANGUAGES = ['it' => 'Italiano', 'en' => 'English', 'es' => 'Español', 'de' => 'Deutsch', 'fr' => 'Français'];

    /** [decimali, migliaia] */
    private const SEPARATORS = [
        'it' => [',', '.'], 'en' => ['.', ','], 'es' => [',', '.'], 'de' => [',', '.'], 'fr' => [',', "\u{202F}"],
    ];

    public static function current(): string
    {
        $lang = substr(\Locale::getDefault(), 0, 2);

        return isset(self::LANGUAGES[$lang]) ? $lang : self::DEFAULT;
    }

    /** @return array{0: string, 1: string} */
    public static function separators(?string $lang = null): array
    {
        return self::SEPARATORS[$lang ?? self::current()];
    }
}
