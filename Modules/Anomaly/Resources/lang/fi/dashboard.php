<?php

declare(strict_types=1);

return [
    'title' => 'Poikkeavat veloitukset',
    'aria_label' => 'Poikkeavat veloitukset — :open',

    'open' => ':count avoin|:count avointa',
    'detectors' => [
        'large' => ':count suuri|:count suurta',
        'first_time' => ':count ensimmäinen kerta|:count ensimmäistä kertaa',
        'duplicate' => ':count kaksoisveloitus|:count kaksoisveloitusta',
    ],
];
