<?php

declare(strict_types=1);

return [
    'heading' => 'Kontovaluta',
    'intro' => 'Den valuta hver konto er angivet i. En ny konto starter i basisvalutaen.',
    'no_accounts' => 'Ingen konti endnu.',
    'legend' => 'Valuta for :name',
    'label' => 'Valuta',
    'help' => 'Den valuta denne konto viser sin saldo i.',
    'save' => 'Gem valuta',
    'saved' => 'Gemt',

    'toast' => [
        'updated' => ':name vises nu i :currency.',
    ],

    'errors' => [
        'unknown' => 'Det er ikke en valuta, denne installation kender.',
    ],

    'warning' => [
        'intro' => 'At ændre denne konto fra :from til :to omdøber den blot. Intet gemt bliver omregnet eller omskrevet.',
        'baseline' => 'Startsaldoen på :amount forbliver præcis det beløb og læses fra nu af som :to.',
        'lines' => 'Denne konto indeholder nu:',
        'reads' => 'Efter ændringen viser denne konto sin :to-linje — nul, hvis den intet har i :to.',
        'confirm' => 'Ændr alligevel',
        'keep' => 'Behold :currency',
    ],
];
