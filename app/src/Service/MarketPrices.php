<?php

namespace App\Service;

use App\Entity\Holding;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Prezzi di mercato da Yahoo Finance (servizio gratuito e non ufficiale, senza chiave).
 *
 * Per ogni simbolo si scarica il prezzo attuale e le chiusure giornaliere da una data in poi; i prezzi
 * in valuta estera si convertono in euro col cambio dello stesso giorno. Tutto resta in cache 20 minuti
 * (gli errori 5, per non insistere): se il servizio non risponde si restituisce null e l'app mostra
 * l'ultimo valore registrato a mano. Al primo errore di rete il servizio si considera fuori uso per
 * 5 minuti, cosi' le pagine non restano ad aspettare un titolo dopo l'altro.
 */
final class MarketPrices
{
    private const CHART = 'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d';
    private const SEARCH = 'https://query2.finance.yahoo.com/v1/finance/search?q=%s&quotesCount=6&newsCount=0';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $pool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Simbolo da usare per il titolo: quello indicato, altrimenti BTC → BTC-EUR per le crypto,
     * altrimenti la ricerca per ISIN/simbolo (risultato tenuto una settimana).
     */
    public function resolve(Holding $holding): ?string
    {
        if ($holding->getQuoteSymbol()) {
            return $holding->getQuoteSymbol();
        }
        $symbol = strtoupper(trim((string) $holding->getSymbol()));
        if ($symbol === '') {
            return null;
        }
        if ($holding->getKind() === 'crypto' && preg_match('/^[A-Z0-9]{2,10}$/', $symbol)) {
            return $symbol.'-EUR';
        }

        return $this->cache->get('quote_search_'.md5($symbol), function (ItemInterface $item) use ($symbol) {
            $data = $this->fetch(sprintf(self::SEARCH, rawurlencode($symbol)));
            $quotes = array_values(array_filter($data['quotes'] ?? [], fn ($q) => isset($q['symbol'])
                && in_array($q['quoteType'] ?? '', ['ETF', 'EQUITY', 'MUTUALFUND', 'CRYPTOCURRENCY', 'INDEX'], true)));
            $item->expiresAfter($quotes ? 7 * 86400 : 3600);

            return $quotes[0]['symbol'] ?? null;
        });
    }

    /**
     * Prezzo attuale in euro e chiusure giornaliere in euro dal giorno $since (qualche giorno prima,
     * per avere la chiusura precedente anche dopo un fine settimana).
     *
     * @return array{price: float, time: \DateTimeImmutable, currency: string, closes: array<string, float>}|null
     */
    public function quote(string $symbol, \DateTimeImmutable $since): ?array
    {
        $raw = $this->chart($symbol, $since);
        if (!$raw) {
            return null;
        }
        $currency = $raw['currency'];
        // Pence inglesi: stessa valuta (sterlina) per il cambio, il rapporto fra prezzi non cambia.
        $fxCurrency = $currency === 'GBp' ? 'GBP' : strtoupper($currency);
        if ($fxCurrency !== 'EUR') {
            $fx = $this->chart($fxCurrency.'EUR=X', $since);
            if (!$fx) {
                return null;
            }
            $raw['price'] *= $fx['price'];
            foreach ($raw['closes'] as $day => $close) {
                $rate = self::onOrBefore($fx['closes'], $day) ?? $fx['price'];
                $raw['closes'][$day] = $close * $rate;
            }
        }

        return ['price' => $raw['price'], 'time' => $raw['time'], 'currency' => $currency, 'closes' => $raw['closes']];
    }

    /** Valore del giorno $day o, se quel giorno non c'e' (festivo, fine settimana), del precedente. */
    public static function onOrBefore(array $closes, string $day): ?float
    {
        $found = null;
        foreach ($closes as $d => $value) {
            if ($d > $day) {
                break;
            }
            $found = $value;
        }

        return $found;
    }

    /** @return array{price: float, time: \DateTimeImmutable, currency: string, closes: array<string, float>}|null */
    private function chart(string $symbol, \DateTimeImmutable $since): ?array
    {
        $from = $since->modify('-10 days')->setTime(0, 0);
        $key = 'quote_chart_'.md5($symbol.'|'.$from->format('Y-m-d'));

        return $this->cache->get($key, function (ItemInterface $item) use ($symbol, $from) {
            $item->expiresAfter(1200);
            $data = $this->fetch(sprintf(self::CHART, rawurlencode($symbol), $from->getTimestamp(), time() + 86400));
            $result = $data['chart']['result'][0] ?? null;
            $meta = $result['meta'] ?? [];
            if (!isset($meta['regularMarketPrice'], $meta['currency'])) {
                $item->expiresAfter(300);

                return null;
            }
            $tz = new \DateTimeZone($meta['exchangeTimezoneName'] ?? 'Europe/Rome');
            $closes = [];
            foreach ($result['timestamp'] ?? [] as $i => $ts) {
                $close = $result['indicators']['quote'][0]['close'][$i] ?? null;
                if ($close !== null) {
                    $closes[(new \DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d')] = (float) $close;
                }
            }
            ksort($closes);

            return [
                'price' => (float) $meta['regularMarketPrice'],
                'time' => (new \DateTimeImmutable('@'.($meta['regularMarketTime'] ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
                'currency' => (string) $meta['currency'],
                'closes' => $closes,
            ];
        });
    }

    private function fetch(string $url): ?array
    {
        $down = $this->pool->getItem('quote_service_down');
        if ($down->isHit()) {
            return null;
        }
        $context = stream_context_create(['http' => [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (Gruzzolo)\r\nAccept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->logger->warning('Prezzi di mercato non raggiungibili', ['url' => $url]);
            $this->pool->save($down->set(true)->expiresAfter(300));

            return null;
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
