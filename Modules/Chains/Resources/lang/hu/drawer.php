<?php

declare(strict_types=1);

return [
    'heading_named' => 'Lánc ehhez: :name',
    'heading' => 'Lánc',

    'unresolved_heading' => 'Nincs kiválasztott tranzakció',
    'unresolved_body' => 'Válassz egy sort a tranzakciólistán, hogy lásd, mi fedezte.',

    'none_heading' => 'Nem található fedezeti lánc',
    'none_body' => 'Ehhez a tranzakcióhoz nem található fedezeti lánc. Ha számítottál rá, jelents be egy jelöltet az áttekintési sorból.',

    'none_beyond_leg' => 'Ezen a szakaszon túl nem található fedezeti lánc.',

    'covers_charges' => ':count ICS-terhelést fedez|:count ICS-terhelést fedez',
    'show_more_fanout' => 'További :count megjelenítése · :shown / :total',

    'confirm' => 'Megerősítés',
    'reject' => 'Elutasítás',
    'confirm_aria' => 'A(z) :id lánckapcsolat megerősítése',
    'reject_aria' => 'A(z) :id lánckapcsolat elutasítása',

    'confidence_tier' => [
        'deterministic' => 'Determinisztikus',
        'confirmed' => 'Megerősítve',
        'candidate' => 'Jelölt',
    ],

    'confidence_aria' => [
        'deterministic' => 'Megbízhatóság: determinisztikus egyezés',
        'confirmed' => 'Megbízhatóság: megerősítve',
        'candidate' => 'Megbízhatóság: jelölt; áttekintésre vár',
    ],
];
