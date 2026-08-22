<?php

declare(strict_types=1);

return [
    'title' => 'Незвичні списання',
    'aria_label' => 'Незвичні списання — :open',

    'open' => ':count відкрите|:count відкриті|:count відкритих',
    'detectors' => [
        'large' => ':count велике|:count великі|:count великих',
        'first_time' => ':count уперше|:count уперше|:count уперше',
        // i18n-review: uk · detectors.duplicate — was the noun "дублікат", swapped for the
        // adjective agreeing with "списання" so the count can govern it. Whether that
        // participle is the word Ukrainian banking copy uses is the open part.
        'duplicate' => ':count дубльоване|:count дубльовані|:count дубльованих',
    ],
];
