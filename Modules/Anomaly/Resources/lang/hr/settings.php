<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Osjetljivost upozorenja',
    'sensitivity_help' => 'Označi terećenja veća od :percent% iznad tvoje uobičajene potrošnje kod tog trgovca ili u toj kategoriji.',

    'min_amount_label' => 'Najmanji iznos terećenja',
    'min_amount_help' => 'Zanemari anomalije na terećenjima manjima od ovog iznosa. Pohranjuje se u centima (€) — 1000 znači 10,00 €.',

    'save' => 'Spremi postavke anomalija',
    'saved' => 'Spremljeno.',

    'suppression' => [
        'summary' => 'Pravila prigušivanja',
        'empty' => 'Još nema pravila prigušivanja. Kad terećenje označiš kao očekivano, ovdje se pojavljuje pravilo.',
        'remove' => 'Ukloni',
        'remove_aria' => 'Ukloni pravilo prigušivanja',
        'removed_toast' => 'Pravilo je uklonjeno',
    ],

    'unknown_merchant' => 'Nepoznat trgovac',

    'detectors' => [
        'large' => 'Veliko terećenje',
        'first_time' => 'Prvi put',
        'duplicate' => 'Dvostruko',
    ],

    'errors' => [
        'sensitivity_range' => 'Osjetljivost mora biti između 1 i 100.',
        'min_amount_negative' => 'Najmanji iznos terećenja ne može biti negativan.',
    ],
];
