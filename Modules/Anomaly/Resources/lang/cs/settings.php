<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Citlivost upozornění',
    'sensitivity_help' => 'Označit platby, které o více než :percent% převyšují tvé obvyklé útraty u daného obchodníka nebo kategorie.',

    'min_amount_label' => 'Minimální částka platby',
    'min_amount_help' => 'Ignorovat anomálie u plateb pod touto částkou. Ukládá se v centech (€) — 1000 znamená 10,00 €.',

    'save' => 'Uložit nastavení anomálií',
    'saved' => 'Uloženo.',

    'suppression' => [
        'summary' => 'Pravidla potlačení',
        'empty' => 'Zatím žádná pravidla potlačení. Když označíš platbu jako očekávanou, objeví se tu pravidlo.',
        'remove' => 'Odebrat',
        'remove_aria' => 'Odebrat pravidlo potlačení',
        'removed_toast' => 'Pravidlo odebráno',
    ],

    'unknown_merchant' => 'Neznámý obchodník',

    'detectors' => [
        'large' => 'Vysoká platba',
        'first_time' => 'Poprvé',
        'duplicate' => 'Duplicita',
    ],

    'errors' => [
        'sensitivity_range' => 'Citlivost musí být mezi 1 a 100.',
        'min_amount_negative' => 'Minimální částka platby nemůže být záporná.',
    ],
];
