<?php

declare(strict_types=1);

return [
    'title' => 'Ungewöhnliche Abbuchungen',
    'aria_label' => 'Ungewöhnliche Abbuchungen — :open',

    // i18n-review: de · open, detectors.* — the adjectives now carry the attributive
    // ending an elided "Abbuchung" wants ("2 große") rather than the predicative form
    // they had ("2 groß"). Which register a compact tally wants is a native call.
    'open' => ':count offene|:count offene',
    'detectors' => [
        'large' => ':count große|:count große',
        'first_time' => ':count zum ersten Mal|:count zum ersten Mal',
        'duplicate' => ':count doppelte|:count doppelte',
    ],
];
