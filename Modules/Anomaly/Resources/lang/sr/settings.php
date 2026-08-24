<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Osetljivost upozorenja',
    'sensitivity_help' => 'Označi zaduženja veća od :percent% iznad tvoje uobičajene potrošnje kod tog trgovca ili u toj kategoriji.',

    'min_amount_label' => 'Najmanji iznos zaduženja',
    'min_amount_help' => 'Zanemari anomalije na zaduženjima manjim od ovog iznosa. Čuva se u centima (:symbol) — 1000 znači :example.',

    'save' => 'Sačuvaj podešavanja anomalija',
    'saved' => 'Sačuvano.',

    'suppression' => [
        'summary' => 'Pravila prigušivanja',
        'empty' => 'Još nema pravila prigušivanja. Kad zaduženje označiš kao očekivano, ovde se pojavljuje pravilo.',
        'remove' => 'Ukloni',
        'remove_aria' => 'Ukloni pravilo prigušivanja',
        'removed_toast' => 'Pravilo je uklonjeno',
    ],

    'unknown_merchant' => 'Nepoznat trgovac',

    'detectors' => [
        'large' => 'Veliko zaduženje',
        'first_time' => 'Prvi put',
        'duplicate' => 'Duplirano',
    ],

    'errors' => [
        'sensitivity_range' => 'Osetljivost mora biti između 1 i 100.',
        'min_amount_negative' => 'Najmanji iznos zaduženja ne može biti negativan.',
    ],
];
