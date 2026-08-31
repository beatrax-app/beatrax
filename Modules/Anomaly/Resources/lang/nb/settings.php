<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Varselfølsomhet',
    'sensitivity_help' => 'Hvor lett Beatrax kaller en belastning uvanlig for den forhandleren eller kategorien, fra 1 til 100. Høyere flagger flere.',

    'min_amount_label' => 'Minste belastningsbeløp',
    'min_amount_help' => 'Ignorer anomalier på belastninger under dette beløpet. Lagres i minste enheter (:symbol) — :minor betyr :example.',

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
