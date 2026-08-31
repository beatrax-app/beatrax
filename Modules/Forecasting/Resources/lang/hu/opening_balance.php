<?php

declare(strict_types=1);

return [
    'help_paypal' => 'A PayPal-exportok nem tartalmaznak egyenlegsorokat, ezért ezt kézzel kell beállítanod.',
    'help_default' => 'Csak akkor írd felül, ha tudod, hogy az aktuális élő egyenleg eltér attól, amit a Beatrax kiszámol.',

    'legend' => 'Előrejelzési nyitó egyenleg ehhez: :name',
    'opening_label' => 'Nyitó egyenleg',
    'opening_placeholder' => 'pl. :amount',
    'as_of_label' => 'A nyitó egyenleg dátuma',
    'as_of_help' => 'Az a dátum, amelyre a fenti összeg igaz.',

    'divergence' => 'Ez több mint :threshold-val eltér attól az egyenlegtől, amelyet a Beatrax az importált tranzakcióidból számol. Biztos vagy benne?',
    'computed_is' => 'A Beatrax :amount összeget számol.',
    'use_beatrax' => 'A Beatrax számát használom',
    'use_mine' => 'A saját számomat használom',

    'save' => 'Nyitó egyenleg mentése',
    'remove' => 'Nyitó egyenleg eltávolítása',
    'saved' => 'Mentve.',
    'removed' => 'Eltávolítva.',

    'toast' => [
        'updated' => 'A nyitó egyenleg frissítve.',
        'removed' => 'A nyitó egyenleg eltávolítva.',
    ],

    'errors' => [
        'invalid_number' => 'A nyitó egyenlegnek érvényes számnak kell lennie.',
        'date_required' => 'Válaszd ki, melyik dátumra vonatkozik ez a nyitó egyenleg.',
        'date_invalid' => 'A nyitó egyenleg dátumának érvényes ISO-dátumnak kell lennie (YYYY-MM-DD).',
        'date_future' => 'A nyitó egyenleg dátuma nem lehet a jövőben.',
    ],
];
