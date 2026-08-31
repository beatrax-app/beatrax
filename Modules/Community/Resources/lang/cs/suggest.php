<?php

declare(strict_types=1);

return [
    'heading' => 'Navrhnout přiřazení',
    'intro' => 'Otevře GitHub v prohlížeči, abys mohl návrh poslat jako koncept PR. Tvé jméno ani e-mail toto zařízení nikdy neopustí.',

    'pattern' => 'Vzor',
    'name' => 'Srozumitelný název',
    'name_placeholder' => 'např. Albert Heijn',
    'category' => 'Kategorie (nepovinné)',
    'category_placeholder' => 'např. Potraviny',
    'region' => 'Region',

    'regions' => [
        'other' => 'Jiný',
    ],

    'yaml_preview' => 'Náhled YAML',

    'cancel' => 'Zrušit',
    'submit' => 'Odeslat jako koncept PR',

    'toast' => 'Návrh se otevřel v prohlížeči.',

    'errors' => [
        'pattern_required' => 'Vzor je povinný.',
        'name_required' => 'Název je povinný.',
        'browser_refused' => 'Prohlížeč se nepodařilo otevřít, takže se nic neodeslalo a nic toto zařízení neopustilo. Zkus to znovu, nebo náhled YAML výše vlož do pull requestu sám.',
    ],
];
