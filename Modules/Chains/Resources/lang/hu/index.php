<?php

declare(strict_types=1);

return [
    'page_title' => 'Láncok',
    'heading' => 'Láncok',
    'review_link' => 'Áttekintési sor →',
    'hints_link' => 'Tippek →',
    'subtitle' => 'Vásárlások, amelyeket egyetlen terhelésbe vontak össze. Minden kártya egy terhelést mutat, és az azt tápláló fizetéseket.',

    'empty_heading' => 'Még nincs lánc',
    'empty_body' => 'Importálj néhány kivonatot (bank, PayPal, kártya), és a feloldó itt automatikusan megjeleníti a számlák közti láncokat.',

    'no_counterparty' => '(nincs partner)',
    'open_from_row' => 'Forrássor megnyitása',
    'open_to_row' => 'Célsor megnyitása',
    'leg_count' => '1 fizetés|:count fizetés',
    'state_aria' => 'Állapot: :state',

    'kind' => [
        'paypal_funding' => 'PayPal-fedezet',
        'ics_bulk_settle' => 'Csoportos iDEAL-elszámolás',
        'funded_by_card_hint' => 'Kártyáról fedezve (tipp)',
        'refund_of_hint' => 'Visszatérítés (tipp)',
    ],
];
