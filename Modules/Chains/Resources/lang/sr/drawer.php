<?php

declare(strict_types=1);

return [
    'heading_named' => 'Lanac za :name',
    'heading' => 'Lanac',

    'unresolved_heading' => 'Nijedna transakcija nije izabrana',
    'unresolved_body' => 'Izaberi red na listi transakcija da vidiš čime je plaćena.',

    'none_heading' => 'Nije pronađen lanac finansiranja',
    'none_body' => 'Za ovu transakciju nije otkriven lanac finansiranja. Ako si ga očekivao, prijavi kandidata iz reda za pregled.',

    'none_beyond_leg' => 'Iza ove deonice nije pronađen lanac finansiranja.',

    'covers_charges' => 'Pokriva :count ICS zaduženje|Pokriva :count ICS zaduženja|Pokriva :count ICS zaduženja',
    'show_more_fanout' => 'Prikaži još :count · :shown od :total',

    'confirm' => 'Potvrdi',
    'reject' => 'Odbij',
    'confirm_aria' => 'Potvrdi vezu lanca :id',
    'reject_aria' => 'Odbij vezu lanca :id',

    'confidence_tier' => [
        'deterministic' => 'Determinističko',
        'confirmed' => 'Potvrđeno',
        'candidate' => 'Kandidat',
    ],

    'confidence_aria' => [
        'deterministic' => 'Pouzdanost: determinističko poklapanje',
        'confirmed' => 'Pouzdanost: potvrđeno',
        'candidate' => 'Pouzdanost: kandidat; treba pregled',
    ],
];
