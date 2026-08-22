<?php

declare(strict_types=1);

return [
    'title' => 'Nietypowe obciążenia',
    'aria_label' => 'Nietypowe obciążenia — :open',

    'open' => ':count otwarte|:count otwarte|:count otwartych',
    'detectors' => [
        'large' => ':count duże|:count duże|:count dużych',
        // i18n-review: pl · detectors.first_time — was "pierwszy raz", which a numeral
        // cannot govern; the adverbial stays fixed across the arms instead. A native
        // should say whether it reads beside a count or wants a noun phrase.
        'first_time' => ':count po raz pierwszy|:count po raz pierwszy|:count po raz pierwszy',
        'duplicate' => ':count zduplikowane|:count zduplikowane|:count zduplikowanych',
    ],
];
