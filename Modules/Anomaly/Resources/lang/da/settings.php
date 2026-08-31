<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Advarselsfølsomhed',
    'sensitivity_help' => 'Hvor let Beatrax kalder en hævning usædvanlig for den forhandler eller kategori, fra 1 til 100. Højere markerer flere.',

    'min_amount_label' => 'Mindste posteringsbeløb',
    'min_amount_help' => 'Ignorér anomalier på posteringer under dette beløb. Gemmes i mindste enheder (:symbol) — :minor betyder :example.',

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
