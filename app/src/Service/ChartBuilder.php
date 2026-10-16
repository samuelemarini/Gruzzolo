<?php

namespace App\Service;

/**
 * Geometria dei grafici SVG disegnati lato server (niente librerie JS, tutto locale).
 * I template ricevono numeri gia' pronti: cerchi, barre, punti.
 */
final class ChartBuilder
{
    /**
     * Ciambella: un cerchio per fetta con stroke-dasharray.
     *
     * @param list<array{value: float, color: string, label?: string}> $slices
     *
     * @return array{size: int, r: float, stroke: int, circles: list<array{color: string, dash: string, offset: string, label: string}>}
     */
    public function donut(array $slices, int $size = 210, int $stroke = 24, float $gap = 3.0): array
    {
        $r = ($size - $stroke) / 2;
        $circumference = 2 * M_PI * $r;
        $total = array_sum(array_column($slices, 'value'));
        $circles = [];
        $cum = 0.0;
        if ($total > 0) {
            $gap = count($slices) > 1 ? $gap : 0;
            foreach ($slices as $slice) {
                $len = $slice['value'] / $total * $circumference;
                $visible = max($len - $gap, 0.6);
                $circles[] = [
                    'color' => $slice['color'],
                    'dash' => sprintf('%.2f %.2f', $visible, $circumference - $visible),
                    'offset' => sprintf('%.2f', -$cum),
                    'label' => $slice['label'] ?? '',
                ];
                $cum += $len;
            }
        }

        return ['size' => $size, 'r' => $r, 'stroke' => $stroke, 'circles' => $circles];
    }

    /**
     * Barre affiancate (es. entrate/uscite per mese) con altezza in percentuale del massimo.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $keys
     *
     * @return array{max: float, rows: list<array<string, mixed>>, ticks: list<array{value: float, pct: float}>}
     */
    public function bars(array $rows, array $keys): array
    {
        $max = 0.0;
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $max = max($max, (float) $row[$key]);
            }
        }
        $top = $this->niceMax($max);
        foreach ($rows as &$row) {
            foreach ($keys as $key) {
                $row[$key.'Pct'] = $top > 0 ? round((float) $row[$key] / $top * 100, 2) : 0;
            }
        }
        unset($row);
        $ticks = [];
        for ($i = 0; $i <= 2; ++$i) {
            $ticks[] = ['value' => $top * $i / 2, 'pct' => $i * 50];
        }

        return ['max' => $top, 'rows' => $rows, 'ticks' => $ticks];
    }

    /**
     * Linee morbide per grafici che si allargano a tutta la larghezza.
     *
     * L'SVG si deforma (preserveAspectRatio="none") per riempire il riquadro; per questo le coordinate
     * escono anche in percentuale: etichette, punto finale e zone di tooltip sono HTML posizionato sopra,
     * cosi' testo e pallino restano della stessa misura a qualsiasi larghezza.
     *
     * @param array<string, list<float|null>> $series nome => valori (stessa lunghezza)
     *
     * @return array{height: int, w: int, h: int, series: array<string, array{line: string, area: string, points: list<array{x: float, y: float, v: float}>, last: ?array{x: float, y: float, v: float}}>, ticks: list<array{pct: float, value: float}>}
     */
    public function lines(array $series, int $width = 320, int $height = 160, bool $fromZero = false): array
    {
        $w = 1000;
        $h = $height;
        $padT = 10;
        $padB = 10;
        $series = array_filter($series, fn ($values) => count(array_filter($values, fn ($v) => $v !== null)) > 0);
        $values = $series ? array_filter(array_merge(...array_values($series)), fn ($v) => $v !== null) : [];
        if (!$values) {
            return ['height' => $height, 'w' => $w, 'h' => $h, 'series' => [], 'ticks' => []];
        }
        $count = max(array_map('count', $series));
        $min = $fromZero ? 0.0 : min($values);
        $max = max($values);
        if ($max - $min < 1e-9) {
            $min -= 1;
            $max += 1;
        }
        $span = $max - $min;
        $min = $fromZero ? 0.0 : $min - $span * 0.12;
        $max += $span * 0.12;
        $step = $this->niceStep(($max - $min) / 3);
        $min = floor($min / $step) * $step;
        $max = ceil($max / $step) * $step;

        $x = fn (int $i) => $count > 1 ? $i * $w / ($count - 1) : $w / 2;
        $y = fn (float $v) => $padT + ($h - $padT - $padB) * (1 - ($v - $min) / ($max - $min));

        $out = [];
        foreach ($series as $name => $list) {
            $pts = [];
            foreach ($list as $i => $v) {
                if ($v !== null) {
                    $pts[] = [$x($i), $y((float) $v), (float) $v];
                }
            }
            $line = $this->smoothPath($pts);
            $first = $pts[0];
            $lastPt = end($pts);
            $out[$name] = [
                'line' => $line,
                'area' => sprintf('%s L%.1f,%.1f L%.1f,%.1fZ', $line, $lastPt[0], $h, $first[0], $h),
                'points' => array_map(fn ($p) => ['x' => round($p[0] / $w * 100, 3), 'y' => round($p[1] / $h * 100, 3), 'v' => $p[2]], $pts),
                'last' => ['x' => round($lastPt[0] / $w * 100, 3), 'y' => round($lastPt[1] / $h * 100, 3), 'v' => $lastPt[2]],
            ];
        }
        $ticks = [];
        for ($v = $min; $v <= $max + $step / 2; $v += $step) {
            $ticks[] = ['pct' => round($y($v) / $h * 100, 3), 'value' => $v, 'y' => round($y($v), 1)];
        }

        return ['height' => $height, 'w' => $w, 'h' => $h, 'series' => $out, 'ticks' => $ticks];
    }

    /**
     * Curva monotona (Fritsch-Carlson): morbida ma senza "rimbalzi" oltre i valori veri,
     * cosi' un saldo che sale non sembra scendere fra due punti.
     *
     * @param list<array{0: float, 1: float}> $p
     */
    private function smoothPath(array $p): string
    {
        $n = count($p);
        if ($n === 1) {
            return sprintf('M%.1f,%.1f L%.1f,%.1f', $p[0][0] - 1, $p[0][1], $p[0][0] + 1, $p[0][1]);
        }
        $d = [];
        for ($i = 0; $i < $n - 1; ++$i) {
            $d[$i] = ($p[$i + 1][1] - $p[$i][1]) / max($p[$i + 1][0] - $p[$i][0], 1e-9);
        }
        $m = [$d[0]];
        for ($i = 1; $i < $n - 1; ++$i) {
            $m[$i] = $d[$i - 1] * $d[$i] <= 0 ? 0.0 : ($d[$i - 1] + $d[$i]) / 2;
        }
        $m[$n - 1] = $d[$n - 2];
        for ($i = 0; $i < $n - 1; ++$i) {
            if ($d[$i] == 0) {
                $m[$i] = $m[$i + 1] = 0.0;
                continue;
            }
            $a = $m[$i] / $d[$i];
            $b = $m[$i + 1] / $d[$i];
            $t = $a * $a + $b * $b;
            if ($t > 9) {
                $k = 3 / sqrt($t);
                $m[$i] = $k * $a * $d[$i];
                $m[$i + 1] = $k * $b * $d[$i];
            }
        }
        $path = sprintf('M%.1f,%.1f', $p[0][0], $p[0][1]);
        for ($i = 0; $i < $n - 1; ++$i) {
            $h = ($p[$i + 1][0] - $p[$i][0]) / 3;
            $path .= sprintf(' C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
                $p[$i][0] + $h, $p[$i][1] + $m[$i] * $h,
                $p[$i + 1][0] - $h, $p[$i + 1][1] - $m[$i + 1] * $h,
                $p[$i + 1][0], $p[$i + 1][1]);
        }

        return $path;
    }

    public function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 0;
        }
        $step = $this->niceStep($max / 2);

        return ceil($max / $step) * $step;
    }

    private function niceStep(float $raw): float
    {
        if ($raw <= 0) {
            return 1;
        }
        $exp = 10 ** floor(log10($raw));
        $f = $raw / $exp;
        $nice = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 2.5 ? 2.5 : ($f <= 5 ? 5 : 10)));

        return $nice * $exp;
    }
}
