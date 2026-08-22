<?php

declare(strict_types=1);

return [
    'title' => 'Neparasti maksājumi',
    'aria_label' => 'Neparasti maksājumi — :open',

    // i18n-review: lv · open, detectors.* — Latvian selects arm 0 for zero, so the
    // genitive plural leads and the singular follows. This tile never renders a zero
    // part, so that arm ships unread; a native should still check it stands alone.
    'open' => ':count atvērtu|:count atvērts|:count atvērti',
    'detectors' => [
        'large' => ':count lielu|:count liels|:count lieli',
        'first_time' => ':count pirmreizēju|:count pirmreizējs|:count pirmreizēji',
        'duplicate' => ':count dublikātu|:count dublikāts|:count dublikāti',
    ],
];
