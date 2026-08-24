<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Varselfølsomhet',
    'sensitivity_help' => 'Merk belastninger som ligger mer enn :percent% over det du vanligvis bruker hos den forhandleren eller i den kategorien.',

    'min_amount_label' => 'Minste belastningsbeløp',
    'min_amount_help' => 'Ignorer anomalier på belastninger under dette beløpet. Lagres i cent (:symbol) — 1000 betyr :example.',

    'save' => 'Lagre anomaliinnstillinger',
    'saved' => 'Lagret.',

    'suppression' => [
        'summary' => 'Unntaksregler',
        'empty' => 'Ingen unntaksregler ennå. Når du merker en belastning som forventet, vises det en regel her.',
        'remove' => 'Slett',
        'remove_aria' => 'Slett unntaksregelen',
        'removed_toast' => 'Regelen er slettet',
    ],

    'unknown_merchant' => 'Ukjent forhandler',

    'detectors' => [
        'large' => 'Stor belastning',
        'first_time' => 'Første gang',
        'duplicate' => 'Duplikat',
    ],

    'errors' => [
        'sensitivity_range' => 'Følsomheten må være mellom 1 og 100.',
        'min_amount_negative' => 'Minste belastningsbeløp kan ikke være negativt.',
    ],
];
