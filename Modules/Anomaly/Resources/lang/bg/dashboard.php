<?php

declare(strict_types=1);

return [
    'title' => 'Необичайни плащания',
    'aria_label' => 'Необичайни плащания — :open',

    'open' => ':count отворено|:count отворени',
    'detectors' => [
        'large' => ':count голямо|:count големи',
        'first_time' => ':count за първи път|:count за първи път',
        // i18n-review: bg · detectors.duplicate — was the noun "дубликат", which cannot
        // agree with the count beside it; this is the adjective agreeing with "плащане"
        // like its three siblings. A native should confirm the word, not the agreement.
        'duplicate' => ':count дублирано|:count дублирани',
    ],
];
