<?php

declare(strict_types=1);

return [
    'heading_named' => 'Łańcuch — :name',
    'heading' => 'Łańcuch',

    'unresolved_heading' => 'Nie wybrano transakcji',
    'unresolved_body' => 'Wybierz wiersz na liście transakcji, aby zobaczyć, z czego została opłacona.',

    'none_heading' => 'Nie znaleziono łańcucha finansowania',
    'none_body' => 'Dla tej transakcji nie wykryto łańcucha finansowania. Jeśli miał się pojawić, zgłoś kandydata z kolejki przeglądu.',

    'none_beyond_leg' => 'Nie znaleziono łańcucha finansowania poza tym odcinkiem.',

    'covers_charges' => 'Pokrywa :count obciążenie ICS|Pokrywa :count obciążenia ICS|Pokrywa :count obciążeń ICS',
    'show_more_fanout' => 'Pokaż więcej: :count · :shown z :total',

    'confirm' => 'Potwierdź',
    'reject' => 'Odrzuć',
    'confirm_aria' => 'Potwierdź powiązanie łańcucha :id',
    'reject_aria' => 'Odrzuć powiązanie łańcucha :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministyczne',
        'confirmed' => 'Potwierdzone',
        'candidate' => 'Kandydat',
    ],

    'confidence_aria' => [
        'deterministic' => 'Pewność: dopasowanie deterministyczne',
        'confirmed' => 'Pewność: potwierdzone',
        'candidate' => 'Pewność: kandydat; wymaga przeglądu',
    ],
];
