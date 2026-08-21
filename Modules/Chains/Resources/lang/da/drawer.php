<?php

declare(strict_types=1);

return [
    'heading_named' => 'Kæde for :name',
    'heading' => 'Kæde',

    'unresolved_heading' => 'Kæden er endnu ikke løst',
    'unresolved_body' => 'Kædeløseren kører stadig. Åbn gennemgangskøen, eller genindlæs om et øjeblik.',

    'none_heading' => 'Der blev ikke fundet nogen finansieringskæde',
    'none_body' => 'Der er ikke fundet nogen finansieringskæde for denne transaktion. Hvis du forventede en, kan du indsende en kandidat fra gennemgangskøen.',

    'none_beyond_leg' => 'Der blev ikke fundet nogen finansieringskæde ud over dette led.',

    'covers_charges' => 'Dækker :count ICS-postering|Dækker :count ICS-posteringer',
    'no_ics_charges' => 'Ingen ICS-posteringer i denne afregning',
    'show_more_fanout' => 'Vis :count flere · :shown af :total',

    'confirm' => 'Bekræft',
    'reject' => 'Afvis',
    'confirm_aria' => 'Bekræft kædeforbindelse :id',
    'reject_aria' => 'Afvis kædeforbindelse :id',

    'confidence_aria' => [
        'deterministic' => 'Sikkerhed: deterministisk match',
        'confirmed' => 'Sikkerhed: bekræftet',
        'candidate' => 'Sikkerhed: kandidat; skal gennemgås',
    ],
];
