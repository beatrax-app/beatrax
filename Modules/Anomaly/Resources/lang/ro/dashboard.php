<?php

declare(strict_types=1);

return [
    'title' => 'Tranzacții neobișnuite',
    'aria_label' => 'Tranzacții neobișnuite — :open',

    // i18n-review: ro · open, detectors.* — the third arm carries the "de" a numeral
    // from 20 up requires, but here it lands on a substantivised adjective with the
    // noun elided. A native should say whether "21 de deschise" stands without it.
    'open' => ':count deschisă|:count deschise|:count de deschise',
    'detectors' => [
        'large' => ':count mare|:count mari|:count de mari',
        'first_time' => ':count prima dată|:count prima dată|:count prima dată',
        'duplicate' => ':count duplicat|:count duplicate|:count de duplicate',
    ],
];
