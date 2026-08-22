<?php

declare(strict_types=1);

return [
    'title' => 'Nezvyčajné platby',
    'aria_label' => 'Nezvyčajné platby — :open',

    'open' => ':count otvorená|:count otvorené|:count otvorených',
    'detectors' => [
        'large' => ':count veľká|:count veľké|:count veľkých',
        'first_time' => ':count prvýkrát|:count prvýkrát|:count prvýkrát',
        // i18n-review: sk · detectors.duplicate — was the noun "duplicita", swapped for the
        // adjective agreeing with "platba" so the count can govern it. The word choice,
        // not the agreement, is what wants a native eye.
        'duplicate' => ':count duplicitná|:count duplicitné|:count duplicitných',
    ],
];
