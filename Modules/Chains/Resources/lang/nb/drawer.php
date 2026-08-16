<?php

declare(strict_types=1);

return [
    'heading_named' => 'Kjede for :name',
    'heading' => 'Kjede',

    'unresolved_heading' => 'Kjeden er ikke løst ennå',
    'unresolved_body' => 'Kjedeløseren kjører fortsatt. Åpne gjennomgangskøen eller last inn på nytt om et øyeblikk.',

    'none_heading' => 'Fant ingen finansieringskjede',
    'none_body' => 'Det er ikke funnet noen finansieringskjede for denne transaksjonen. Hvis du ventet en, kan du sende inn en kandidat fra gjennomgangskøen.',

    'none_beyond_leg' => 'Fant ingen finansieringskjede utover dette leddet.',

    'covers_charges' => 'Dekker :count ICS-belastninger',
    'no_ics_charges' => 'Ingen ICS-belastninger i dette oppgjøret',
    'show_more_fanout' => 'Vis :count til · :shown av :total',

    'confirm' => 'Bekreft',
    'reject' => 'Avvis',
    'confirm_aria' => 'Bekreft kjedelenke :id',
    'reject_aria' => 'Avvis kjedelenke :id',

    'confidence_aria' => [
        'deterministic' => 'Sikkerhet: deterministisk treff',
        'confirmed' => 'Sikkerhet: bekreftet',
        'candidate' => 'Sikkerhet: kandidat; må gjennomgås',
    ],
];
