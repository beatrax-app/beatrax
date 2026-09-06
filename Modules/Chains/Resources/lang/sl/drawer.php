<?php

declare(strict_types=1);

return [
    'heading_named' => 'Veriga za :name',
    'heading' => 'Veriga',

    'unresolved_heading' => 'Nobena transakcija ni izbrana',
    'unresolved_body' => 'Izberi vrstico na seznamu transakcij, da vidiš, kaj jo je plačalo.',

    'none_heading' => 'Veriga financiranja ni bila najdena',
    'none_body' => 'Za to transakcijo ni zaznane verige financiranja. Če si jo pričakoval, prijavi kandidata iz čakalne vrste za pregled.',

    'none_beyond_leg' => 'Onkraj tega člena veriga financiranja ni bila najdena.',

    'covers_charges' => 'Pokriva :count bremenitev ICS|Pokriva :count bremenitvi ICS|Pokriva :count bremenitve ICS|Pokriva :count bremenitev ICS',
    'show_more_fanout' => 'Prikaži še :count · :shown od :total',

    'confirm' => 'Potrdi',
    'reject' => 'Zavrni',
    'confirm_aria' => 'Potrdi povezavo verige :id',
    'reject_aria' => 'Zavrni povezavo verige :id',

    'confidence_tier' => [
        'deterministic' => 'Determinističen',
        'confirmed' => 'Potrjeno',
        'candidate' => 'Kandidat',
    ],

    'confidence_aria' => [
        'deterministic' => 'Zanesljivost: determinističen zadetek',
        'confirmed' => 'Zanesljivost: potrjeno',
        'candidate' => 'Zanesljivost: kandidat; potrebuje pregled',
    ],
];
