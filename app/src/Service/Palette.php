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
