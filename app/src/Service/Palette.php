<?php

namespace App\Service;

/** Colori e icone proposti nei selettori di categorie, conti, etichette, veicoli e titoli. */
final class Palette
{
    public const COLORS = [
        '#8AB4FF' => 'Blu', '#7FD6FF' => 'Azzurro', '#6FDCCB' => 'Verde acqua', '#9BE7A0' => 'Verde',
        '#C8F169' => 'Lime', '#F3E06A' => 'Giallo', '#FFB86B' => 'Arancio', '#FF8A80' => 'Corallo',
        '#FF9EC7' => 'Rosa', '#C3A6FF' => 'Viola', '#D9B48F' => 'Sabbia', '#A3A59F' => 'Grigio',
    ];

    /**
     * Colori d'accento (bottone principale, voce attiva, evidenziazioni), proposti anche per l'icona dell'app:
     * nome, colore pieno, variante scura per il testo sul tema chiaro (contrasto >= 5:1 sullo sfondo).
     * Niente corallo/rosso: e' il colore delle uscite.
     *
     * @var array<string, array{string, string, string}>
     */
    public const ACCENTS = [
        'lime' => ['Lime', '#C8F169', '#4A6800'],
        'verde' => ['Verde', '#9BE7A0', '#2E6E33'],
        'acqua' => ['Verde acqua', '#6FDCCB', '#1A6C60'],
        'azzurro' => ['Azzurro', '#7FD6FF', '#0B6690'],
        'blu' => ['Blu', '#8AB4FF', '#2F5CB8'],
        'viola' => ['Viola', '#C3A6FF', '#6743C4'],
        'rosa' => ['Rosa', '#FF9EC7', '#B2336D'],
        'arancio' => ['Arancio', '#FFB86B', '#954D00'],
        'giallo' => ['Giallo', '#F3E06A', '#6E6009'],
        'sabbia' => ['Sabbia', '#D9B48F', '#8A5A2B'],
    ];

    /** Colore originale dell'icona dell'app (indaco, simbolo bianco): non e' un accento. */
    public const APP_ICON = 'indaco';

    /** Valore di User::iconColor per l'icona che segue l'accento. */
    public const ICON_FOLLOWS_ACCENT = 'accento';

    public const CATEGORY_ICONS = [
        'house', 'shopping-cart', 'utensils', 'coffee', 'pizza', 'beer', 'car', 'fuel', 'bus', 'train-front',
        'plane', 'ticket', 'film', 'music', 'gamepad-2', 'dumbbell', 'heart-pulse', 'pill', 'stethoscope', 'shirt',
        'gift', 'baby', 'dog', 'cat', 'paw-print', 'book-open', 'graduation-cap', 'smartphone', 'laptop', 'tv',
        'wifi', 'zap', 'flame', 'droplet', 'wrench', 'hammer', 'sofa', 'briefcase', 'banknote', 'hand-coins',
        'undo-2', 'receipt', 'repeat', 'shield', 'scissors', 'sparkles', 'palette', 'umbrella', 'circle-parking',
        'landmark', 'piggy-bank', 'trending-up', 'euro', 'key-round', 'ice-cream-cone', 'package', 'globe',
        'party-popper', 'trophy', 'bed', 'cigarette', 'tree-palm', 'popcorn', 'volleyball', 'stamp', 'martini',
        'shapes', 'inbox', 'chart-candlestick', 'chart-line', 'toothbrush', 'house-heart', 'users', 'motorbike', 'bus',
        'arrow-left-right', 'circle-ellipsis',
    ];
}
