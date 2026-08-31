<?php

declare(strict_types=1);

return [
    'heading_named' => 'Ketju kohteelle :name',
    'heading' => 'Ketju',

    'unresolved_heading' => 'Ketjua ei ole vielä ratkaistu',
    'unresolved_body' => 'Ketjunratkaisija on yhä käynnissä. Avaa tarkistusjono tai päivitä hetken kuluttua.',

    'none_heading' => 'Rahoitusketjua ei löytynyt',
    'none_body' => 'Tälle tapahtumalle ei havaittu rahoitusketjua. Jos odotit sellaista, tee ehdotus tarkistusjonosta.',

    'none_beyond_leg' => 'Tämän osuuden jälkeen ei löytynyt rahoitusketjua.',

    'covers_charges' => 'Kattaa :count ICS-veloituksen|Kattaa :count ICS-veloitusta',
    'show_more_fanout' => 'Näytä :count lisää · :shown / :total',

    'confirm' => 'Vahvista',
    'reject' => 'Hylkää',
    'confirm_aria' => 'Vahvista ketjulinkki :id',
    'reject_aria' => 'Hylkää ketjulinkki :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministinen',
        'confirmed' => 'Vahvistettu',
        'candidate' => 'Ehdokas',
    ],

    'confidence_aria' => [
        'deterministic' => 'Varmuus: deterministinen osuma',
        'confirmed' => 'Varmuus: vahvistettu',
        'candidate' => 'Varmuus: ehdokas, vaatii tarkistuksen',
    ],
];
