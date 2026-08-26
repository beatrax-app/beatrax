<?php

declare(strict_types=1);

return [
    'heading' => 'Valuta računa',
    'intro' => 'Valuta u kojoj je denominiran svaki račun. Novi račun počinje u osnovnoj valuti.',
    'no_accounts' => 'Još nema računa.',
    'legend' => 'Valuta računa :name',
    'label' => 'Valuta',
    'help' => 'Valuta u kojoj ovaj račun iskazuje svoje stanje.',
    'save' => 'Sačuvaj valutu',
    'saved' => 'Sačuvano',

    'toast' => [
        'updated' => ':name sada iskazuje iznose u :currency.',
    ],

    'errors' => [
        'unknown' => 'Ova instalacija ne poznaje tu valutu.',
    ],

    'warning' => [
        'intro' => 'Promena računa iz :from u :to samo menja oznaku. Ništa sačuvano se ne pretvara niti prepisuje.',
        'baseline' => 'Početno stanje od :amount ostaje tačno taj iznos i od sada se čita kao :to.',
        'lines' => 'Ovaj račun trenutno sadrži:',
        'reads' => 'Nakon promene ovaj račun iskazuje svoj red :to — nulu ako u :to ne drži ništa.',
        'confirm' => 'Ipak promeni',
        'keep' => 'Zadrži :currency',
    ],
];
