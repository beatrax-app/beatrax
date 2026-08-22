<?php

declare(strict_types=1);

return [
    'title' => 'Cargos inusuales',
    'aria_label' => 'Cargos inusuales — :open',

    'open' => ':count abierto|:count abiertos',
    'detectors' => [
        'large' => ':count elevado|:count elevados',
        'first_time' => ':count por primera vez|:count por primera vez',
        'duplicate' => ':count duplicado|:count duplicados',
    ],
];
