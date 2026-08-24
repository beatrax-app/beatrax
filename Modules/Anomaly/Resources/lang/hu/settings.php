<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Riasztás érzékenysége',
    'sensitivity_help' => 'Mennyire könnyen minősíti a Beatrax szokatlannak egy terhelést ennél a kereskedőnél vagy kategóriánál, 1-től 100-ig. Magasabb érték többet jelöl meg.',

    'min_amount_label' => 'Minimális terhelési összeg',
    'min_amount_help' => 'Az ennél kisebb terheléseknél ne jelezzen anomáliát. Centben tárolva (:symbol) — az 1000 azt jelenti: :example.',

    'save' => 'Anomáliabeállítások mentése',
    'saved' => 'Mentve.',

    'suppression' => [
        'summary' => 'Elnyomási szabályok',
        'empty' => 'Még nincs elnyomási szabály. Ha egy terhelést vártként jelölsz meg, itt megjelenik egy szabály.',
        'remove' => 'Eltávolítás',
        'remove_aria' => 'Elnyomási szabály eltávolítása',
        'removed_toast' => 'A szabály eltávolítva',
    ],

    'unknown_merchant' => 'Ismeretlen kereskedő',

    'detectors' => [
        'large' => 'Nagy összegű terhelés',
        'first_time' => 'Első alkalom',
        'duplicate' => 'Duplikátum',
    ],

    'errors' => [
        'sensitivity_range' => 'Az érzékenységnek 1 és 100 között kell lennie.',
        'min_amount_negative' => 'A minimális terhelési összeg nem lehet negatív.',
    ],
];
