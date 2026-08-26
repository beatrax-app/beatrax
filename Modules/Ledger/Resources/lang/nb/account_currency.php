<?php

declare(strict_types=1);

return [
    'heading' => 'Kontovaluta',
    'intro' => 'Valutaen hver konto er angitt i. En ny konto starter i basisvalutaen.',
    'no_accounts' => 'Ingen kontoer ennå.',
    'legend' => 'Valuta for :name',
    'label' => 'Valuta',
    'help' => 'Valutaen denne kontoen viser saldoen sin i.',
    'save' => 'Lagre valuta',
    'saved' => 'Lagret',

    'toast' => [
        'updated' => ':name vises nå i :currency.',
    ],

    'errors' => [
        'unknown' => 'Det er ikke en valuta denne installasjonen kjenner.',
    ],

    'warning' => [
        'intro' => 'Å endre denne kontoen fra :from til :to gir den bare en ny merkelapp. Ingenting lagret blir omregnet eller skrevet om.',
        'baseline' => 'Inngående saldo på :amount blir stående på nøyaktig det beløpet og leses fra nå av som :to.',
        'lines' => 'Denne kontoen inneholder nå:',
        'reads' => 'Etter endringen viser denne kontoen :to-linjen sin — null hvis den ikke har noe i :to.',
        'confirm' => 'Endre likevel',
        'keep' => 'Behold :currency',
    ],
];
