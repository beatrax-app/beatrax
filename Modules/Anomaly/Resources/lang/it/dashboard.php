<?php

declare(strict_types=1);

return [
    'title' => 'Addebiti insoliti',
    'aria_label' => 'Addebiti insoliti — :open',

    'open' => ':count aperto|:count aperti',
    'detectors' => [
        'large' => ':count elevato|:count elevati',
        'first_time' => ':count prima volta|:count prime volte',
        'duplicate' => ':count duplicato|:count duplicati',
    ],
];
