<?php

declare(strict_types=1);

return [
    'title' => 'Neobvyklé platby',
    'aria_label' => 'Neobvyklé platby — :open',

    'open' => ':count otevřená|:count otevřené|:count otevřených',
    'detectors' => [
        'large' => ':count velká|:count velké|:count velkých',
        'first_time' => ':count poprvé|:count poprvé|:count poprvé',
        // i18n-review: cs · detectors.duplicate — was the noun "duplicita", swapped for the
        // adjective so it agrees with the count like its siblings. Whether a Czech reader
        // would sooner see "duplicitní platba" spelled out is the open part.
        'duplicate' => ':count duplicitní|:count duplicitní|:count duplicitních',
    ],
];
