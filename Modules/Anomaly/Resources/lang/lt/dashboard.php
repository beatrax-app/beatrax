<?php

declare(strict_types=1);

return [
    'title' => 'Neįprasti mokėjimai',
    'aria_label' => 'Neįprasti mokėjimai — :open',

    // i18n-review: lt · open — was the impersonal "neperžiūrėta", which no numeral
    // can govern; this agrees with "mokėjimas" across the three arms. Whether the
    // impersonal reads better in a tally than the adjective is a native call.
    'open' => ':count neperžiūrėtas|:count neperžiūrėti|:count neperžiūrėtų',
    'detectors' => [
        'large' => ':count didelis|:count dideli|:count didelių',
        // i18n-review: lt · detectors.first_time — the accusative adverbial stays fixed
        // across all three arms, so a count never reaches it. A native should say
        // whether "1 pirmą kartą" reads at all, or wants a noun phrase instead.
        'first_time' => ':count pirmą kartą|:count pirmą kartą|:count pirmą kartą',
        'duplicate' => ':count dublikatas|:count dublikatai|:count dublikatų',
    ],
];
