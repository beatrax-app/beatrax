<?php

declare(strict_types=1);

return [
    'heading' => 'Megfeleltetés javaslása',
    'intro' => 'Megnyitja a GitHubot a böngésződben, hogy piszkozat PR-ként beküldhesd a javaslatot. A neved és az e-mail-címed soha nem hagyja el ezt az eszközt.',

    'pattern' => 'Minta',
    'name' => 'Beszédes név',
    'name_placeholder' => 'pl. Albert Heijn',
    'category' => 'Kategória (opcionális)',
    'category_placeholder' => 'pl. Élelmiszer',
    'region' => 'Régió',

    'regions' => [
        'nl' => 'NL — Hollandia',
        'be' => 'BE — Belgium',
        'de' => 'DE — Németország',
        'fr' => 'FR — Franciaország',
        'other' => 'Egyéb',
    ],

    'yaml_preview' => 'YAML-előnézet',

    'cancel' => 'Mégse',
    'submit' => 'Beküldés piszkozat PR-ként',

    'toast' => 'A javaslat megnyílt a böngésződben.',

    'errors' => [
        'pattern_required' => 'A minta megadása kötelező.',
        'name_required' => 'A név megadása kötelező.',
    ],
];
