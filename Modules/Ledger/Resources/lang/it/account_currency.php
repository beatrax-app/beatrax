<?php

declare(strict_types=1);

return [
    'heading' => 'Valuta del conto',
    'intro' => 'La valuta in cui è denominato ogni conto. Un nuovo conto parte dalla valuta di base.',
    'no_accounts' => 'Nessun conto per ora.',
    'legend' => 'Valuta di :name',
    'label' => 'Valuta',
    'help' => 'La valuta in cui questo conto espone il proprio saldo.',
    'save' => 'Salva valuta',
    'saved' => 'Salvato',

    'toast' => [
        'updated' => ':name ora espone gli importi in :currency.',
    ],

    'errors' => [
        'unknown' => 'Questa valuta non è nota a questa installazione.',
    ],

    'warning' => [
        'intro' => 'Cambiare questo conto da :from a :to lo rietichetta soltanto. Nulla di ciò che è memorizzato viene convertito o riscritto.',
        'baseline' => 'Il saldo iniziale di :amount resta esattamente quella cifra e da ora viene letto come :to.',
        'lines' => 'Questo conto contiene attualmente:',
        'reads' => 'Dopo la modifica questo conto espone la sua riga :to — zero se non detiene nulla in :to.',
        'confirm' => 'Cambia comunque',
        'keep' => 'Mantieni :currency',
    ],
];
