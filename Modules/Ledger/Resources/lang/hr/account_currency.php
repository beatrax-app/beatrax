<?php

declare(strict_types=1);

return [
    'heading' => 'Valuta računa',
    'intro' => 'Valuta u kojoj je denominiran svaki račun. Novi račun počinje u osnovnoj valuti.',
    'no_accounts' => 'Još nema računa.',
    'legend' => 'Valuta računa :name',
    'label' => 'Valuta',
    'help' => 'Valuta u kojoj ovaj račun iskazuje svoje stanje.',
    'save' => 'Spremi valutu',
    'saved' => 'Spremljeno',

    'toast' => [
        'updated' => ':name sada iskazuje iznose u :currency.',
    ],

    'errors' => [
        'unknown' => 'Ova instalacija ne poznaje tu valutu.',
    ],

    'warning' => [
        'intro' => 'Promjena računa iz :from u :to samo mijenja oznaku. Ništa pohranjeno ne pretvara se niti prepisuje.',
        'baseline' => 'Početno stanje od :amount ostaje točno taj iznos i od sada se čita kao :to.',
        'lines' => 'Ovaj račun trenutačno sadrži:',
        'reads' => 'Nakon promjene ovaj račun iskazuje svoj redak :to — nulu ako u :to ne drži ništa.',
        'confirm' => 'Ipak promijeni',
        'keep' => 'Zadrži :currency',
    ],
];
