<?php

declare(strict_types=1);

return [
    'title' => 'Ongebruikelijke afschrijvingen',
    'aria_label' => 'Ongebruikelijke afschrijvingen — :open',

    // i18n-review: nl · open, detectors.* — the adjectives take the attributive -e an
    // elided "afschrijving" wants ("2 grote") rather than the predicative form they
    // had ("2 groot"). Which one a compact tally wants is a native call.
    'open' => ':count openstaande|:count openstaande',
    'detectors' => [
        'large' => ':count grote|:count grote',
        'first_time' => ':count eerste keer|:count eerste keer',
        'duplicate' => ':count dubbele|:count dubbele',
    ],
];
