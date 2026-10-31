<?php

namespace App\Util;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Traduzione dove non arriva l'iniezione delle dipendenze (entita', helper statici): il traduttore
 * lo aggancia il Kernel all'avvio. Senza (test unitari) restituisce l'italiano coi parametri sostituiti.
 */
final class Trans
{
    /**
     * Etichette italiane che in altri punti dell'interfaccia hanno un altro significato: "Altro" e' anche
     * la voce di menu ("More"), qui invece vuol dire "un altro tipo" ("Other"). Si traducono con una
     * chiave a parte; se manca la traduzione (italiano) resta l'etichetta originale.
     */
    private const CONTEXT = ['Altro' => 'Altro (generico)'];

    /** @var (\Closure(): TranslatorInterface)|null */
    private static ?\Closure $resolver = null;

    /** @param \Closure(): TranslatorInterface $resolver */
    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function t(string $id, array $params = []): string
    {
        $translator = self::$resolver ? (self::$resolver)() : null;

        return $translator ? $translator->trans($id, $params) : strtr($id, $params);
    }

    /** Etichetta di una costante (tipi di conto, veicolo, titolo...): l'italiano e' la chiave. */
    public static function label(string $label): string
    {
        if (isset(self::CONTEXT[$label])) {
            $translated = self::t(self::CONTEXT[$label]);

            return $translated === self::CONTEXT[$label] ? $label : $translated;
        }

        return self::t($label);
    }

    /**
     * @param array<string, string> $labels chiave => etichetta italiana
     *
     * @return array<string, string> stesse chiavi, etichette tradotte
     */
    public static function labels(array $labels): array
    {
        return array_map(self::label(...), $labels);
    }
}
