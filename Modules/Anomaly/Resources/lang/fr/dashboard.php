<?php

declare(strict_types=1);

return [
    'title' => 'Débits inhabituels',
    'aria_label' => 'Débits inhabituels — :open',

    'open' => ':count en cours|:count en cours',
    'detectors' => [
        'large' => ':count important|:count importants',
        // i18n-review: fr · detectors.first_time — "2 premières fois" is the agreement the
        // plural arm forces, but it reads as an ordinal rather than "seen for the first
        // time". A native may want a participle such as "inédit" instead.
        'first_time' => ':count première fois|:count premières fois',
        'duplicate' => ':count doublon|:count doublons',
    ],
];
