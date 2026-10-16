<?php

namespace App\Import;

/**
 * I trasferimenti fra conti arrivano come due righe (uscita da uno, entrata nell'altro) con lo
 * stesso istante e lo stesso importo: si uniscono in un giroconto. Quelli spaiati diventano
 * uscite o entrate normali secondo il segno.
 */
final class TransferPairing
{
    /**
     * @param list<ImportRow> $rows con kind 'transfer:out' / 'transfer:in' per i lati dei trasferimenti
     *
     * @return list<ImportRow>
     */
    public static function pair(array $rows): array
    {
        $outs = [];
        foreach ($rows as $i => $row) {
            if ($row->kind === 'transfer:out') {
                $outs[$row->date->getTimestamp().'|'.$row->amount][] = $i;
            }
        }
        $drop = [];
        foreach ($rows as $i => $row) {
            if ($row->kind !== 'transfer:in') {
                continue;
            }
            $key = $row->date->getTimestamp().'|'.$row->amount;
            if (!empty($outs[$key])) {
                $j = array_shift($outs[$key]);
                $rows[$j]->kind = 'transfer';
                $rows[$j]->toWallet = $row->wallet;
                $drop[$i] = true;
            }
        }
        $result = [];
        foreach ($rows as $i => $row) {
            if (isset($drop[$i])) {
                continue;
            }
            if ($row->kind === 'transfer:out') {
                $row->kind = 'expense';
            } elseif ($row->kind === 'transfer:in') {
                $row->kind = 'income';
            }
            $result[] = $row;
        }

        return $result;
    }
}
