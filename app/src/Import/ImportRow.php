<?php

namespace App\Import;

/** Una riga del file gia' interpretata, indipendente dall'app di provenienza. */
final class ImportRow
{
    /**
     * @param 'expense'|'income'|'transfer' $kind
     * @param list<string>                  $tags
     */
    public function __construct(
        public readonly int $line,
        public readonly \DateTimeImmutable $date,
        public string $kind,
        public readonly string $amount,
        public readonly string $wallet,
        public readonly ?string $category,
        public readonly string $description,
        public readonly array $tags,
        public readonly string $currency,
        /** Campi che identificano la riga, per l'impronta anti-doppioni. */
        public readonly string $fingerprint,
        public ?string $toWallet = null,
    ) {
    }

    /** Chiave della categoria nel mapping: lo stesso nome puo' essere sia entrata sia uscita. */
    public function categoryKey(): string
    {
        return $this->kind.'|'.($this->category ?? '');
    }
}
