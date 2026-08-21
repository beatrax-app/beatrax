<?php

declare(strict_types=1);

return [
    'heading_named' => 'Lánc ehhez: :name',
    'heading' => 'Lánc',

    'unresolved_heading' => 'A lánc még nincs feloldva',
    'unresolved_body' => 'A láncfeloldó még fut. Nyisd meg az áttekintési sort, vagy frissíts kis idő múlva.',

    'none_heading' => 'Nem található fedezeti lánc',
    'none_body' => 'Ehhez a tranzakcióhoz nem található fedezeti lánc. Ha számítottál rá, jelents be egy jelöltet az áttekintési sorból.',

    'none_beyond_leg' => 'Ezen a szakaszon túl nem található fedezeti lánc.',

    'covers_charges' => ':count ICS-terhelést fedez|:count ICS-terhelést fedez',
    'no_ics_charges' => 'Ebben az elszámolásban nincs ICS-terhelés',
    'show_more_fanout' => 'További :count megjelenítése · :shown / :total',

    'confirm' => 'Megerősítés',
    'reject' => 'Elutasítás',
    'confirm_aria' => 'A(z) :id lánckapcsolat megerősítése',
    'reject_aria' => 'A(z) :id lánckapcsolat elutasítása',

    'confidence_aria' => [
        'deterministic' => 'Megbízhatóság: determinisztikus egyezés',
        'confirmed' => 'Megbízhatóság: megerősítve',
        'candidate' => 'Megbízhatóság: jelölt; áttekintésre vár',
    ],
];
