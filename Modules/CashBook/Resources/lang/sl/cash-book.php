<?php

declare(strict_types=1);

return [
    'page_title' => 'Blagajniška knjiga',
    'heading' => 'Blagajniška knjiga',
    'intro' => 'Ročno zabeleži gotovino in druge stroške zunaj banke. Ročni vnosi se stekajo v isto glavno knjigo kot uvozi — kategorizirajo se, povežejo se z nasprotno stranko, vključijo v prepoznavanje ponavljajočih plačil in štejejo v tvoj mesec.',

    'direction' => 'Smer',
    'expense' => 'Strošek',
    'income' => 'Prihodek',

    'amount' => 'Znesek (:symbol)',
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
    'delete_entry_caption' => 'Izbriši',
    'delete' => 'Izbriši',
    'delete_confirm' => 'Izbrišem ta vnos?',
    'delete_keep' => 'Obdrži',

    'errors' => [
        'amount_positive' => 'Vnesi znesek, večji od nič.',
        'amount_too_large' => 'Ta znesek je prevelik. Preveri števke.',
        // i18n-review: sl · amount_unreadable — the instrumental "z ... decimalnimi mesti"
        // wants "s" before a spoken 3 or 4 and "z" before 1, 2 and 8, and a digit hides
        // which. Rewritten to "na", which never alternates; a native should confirm it reads.
        'amount_unreadable' => 'Zneska ni bilo mogoče prebrati. Vnesi ga na največ :decimals decimalno mesto, na primer :example.|Zneska ni bilo mogoče prebrati. Vnesi ga na največ :decimals decimalni mesti, na primer :example.|Zneska ni bilo mogoče prebrati. Vnesi ga na največ :decimals decimalna mesta, na primer :example.|Zneska ni bilo mogoče prebrati. Vnesi ga na največ :decimals decimalnih mest, na primer :example.',
        'amount_unreadable_whole' => 'Zneska ni bilo mogoče prebrati. Ta valuta nima decimalnih mest, zato vnesi celo število, na primer :example.',
        'invalid_date' => 'Vnesi veljaven datum.',
        'not_recorded' => 'Vnos ni bil zabeležen. Poskusi ga dodati znova.',
    ],

    'toast' => [
        'added' => 'Gotovinski vnos je dodan.',
        'removed' => 'Gotovinski vnos je odstranjen.',
        'reconciled_locked' => 'Ta transakcija je usklajena. Razveljavi uskladitev, da narediš spremembe.',
    ],
];
