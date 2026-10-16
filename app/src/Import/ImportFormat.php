<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Un formato di file supportato (Spendee, ...): riconosce l'intestazione e interpreta le righe. */
#[AutoconfigureTag('app.import_format')]
interface ImportFormat
{
    public function name(): string;

    public function label(): string;

    /** @param list<string> $header */
    public function supports(array $header): bool;

    /**
     * @param array<string, string> $row colonne per nome
     *
     * @throws \InvalidArgumentException se la riga non e' valida (il messaggio finisce nel riepilogo)
     */
    public function parse(array $row, int $line): ImportRow;

    /**
     * Righe da unire dopo la lettura (es. i due lati di un trasferimento).
     *
     * @param list<ImportRow> $rows
     *
     * @return list<ImportRow>
     */
    public function finalize(array $rows): array;
}
