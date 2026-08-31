<?php

declare(strict_types=1);

return [
    'heading_named' => 'Lanac za :name',
    'heading' => 'Lanac',

    'unresolved_heading' => 'Lanac još nije razriješen',
    'unresolved_body' => 'Razrješivač lanaca još radi. Otvori red za pregled ili osvježi za koji trenutak.',

    'none_heading' => 'Nije pronađen lanac financiranja',
    'none_body' => 'Za ovu transakciju nije otkriven lanac financiranja. Ako si ga očekivao, prijavi kandidata iz reda za pregled.',

    'none_beyond_leg' => 'Iza ove dionice nije pronađen lanac financiranja.',

    'covers_charges' => 'Pokriva :count ICS terećenje|Pokriva :count ICS terećenja|Pokriva :count ICS terećenja',
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
        'deterministic' => 'Pouzdanost: determinističko podudaranje',
        'confirmed' => 'Pouzdanost: potvrđeno',
        'candidate' => 'Pouzdanost: kandidat; treba pregled',
    ],
];
