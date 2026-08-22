<?php

declare(strict_types=1);

return [
    'title' => 'Ebatavalised maksed',
    'aria_label' => 'Ebatavalised maksed — :open',

    'open' => ':count lahtine|:count lahtist',
    'detectors' => [
        'large' => ':count suur|:count suurt',
        'first_time' => ':count esmakordne|:count esmakordset',
        'duplicate' => ':count duplikaat|:count duplikaati',
    ],
];
