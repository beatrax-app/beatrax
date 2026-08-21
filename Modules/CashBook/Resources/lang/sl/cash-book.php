<?php

declare(strict_types=1);

return [
    'page_title' => 'Blagajniška knjiga',
    'heading' => 'Blagajniška knjiga',
    'intro' => 'Ročno zabeleži gotovino in druge stroške zunaj banke. Ročni vnosi se stekajo v isto glavno knjigo kot uvozi — kategorizirajo se, vključijo v prepoznavanje ponavljajočih plačil in štejejo v tvoj mesec.',

    'direction' => 'Smer',
    'expense' => 'Strošek',
    'income' => 'Prihodek',

    'amount' => 'Znesek (€)',
    'date' => 'Datum',
    'counterparty' => 'Nasprotna stranka',
    'counterparty_placeholder' => 'npr. Pekarna',
    'category' => 'Kategorija',
    'optional' => '(neobvezno)',
    'uncategorized' => 'Brez kategorije',
    'note' => 'Opomba',

    'add_entry' => 'Dodaj vnos',
    'manual_entries' => 'Ročni vnosi',
    'no_entries' => 'Ročnih vnosov še ni.',
    'delete_entry' => 'Izbriši vnos',
    'delete' => 'Izbriši',
    'delete_confirm' => 'Izbrišem ta vnos?',
    'delete_keep' => 'Obdrži',

    'errors' => [
        'amount_positive' => 'Vnesi znesek, večji od nič.',
        'amount_too_large' => 'Ta znesek je prevelik. Preveri števke.',
        'amount_unreadable' => 'Tega zneska ni bilo mogoče prebrati. Vnesite ga brez ločila tisočic in z največ dvema decimalkama, na primer :example.',
        'invalid_date' => 'Vnesi veljaven datum.',
    ],

    'toast' => [
        'added' => 'Gotovinski vnos je dodan.',
        'removed' => 'Gotovinski vnos je odstranjen.',
    ],
];
