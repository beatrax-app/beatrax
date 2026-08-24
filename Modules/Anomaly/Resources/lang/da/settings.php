<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Advarselsfølsomhed',
    'sensitivity_help' => 'Markér posteringer, der ligger mere end :percent% over dit typiske forbrug hos den forhandler eller i den kategori.',

    'min_amount_label' => 'Mindste posteringsbeløb',
    'min_amount_help' => 'Ignorér anomalier på posteringer under dette beløb. Gemmes i cent (:symbol) — 1000 betyder :example.',

    'save' => 'Gem anomaliindstillinger',
    'saved' => 'Gemt.',

    'suppression' => [
        'summary' => 'Undtagelsesregler',
        'empty' => 'Ingen undtagelsesregler endnu. Når du markerer en postering som forventet, vises der en regel her.',
        'remove' => 'Slet',
        'remove_aria' => 'Slet undtagelsesreglen',
        'removed_toast' => 'Reglen er slettet',
    ],

    'unknown_merchant' => 'Ukendt forhandler',

    'detectors' => [
        'large' => 'Stor postering',
        'first_time' => 'Første gang',
        'duplicate' => 'Dublet',
    ],

    'errors' => [
        'sensitivity_range' => 'Følsomheden skal være mellem 1 og 100.',
        'min_amount_negative' => 'Mindste posteringsbeløb kan ikke være negativt.',
    ],
];
