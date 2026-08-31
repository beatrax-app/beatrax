<?php

declare(strict_types=1);

return [
    'page_title' => 'Registru de casă',
    'heading' => 'Registru de casă',
    'intro' => 'Înregistrează manual cheltuielile în numerar și pe cele din afara băncii. Intrările manuale ajung în același registru ca importurile — se categorisesc, se leagă de o contraparte, intră în detecția recurențelor și se numără în luna ta.',

    'direction' => 'Sens',
    'expense' => 'Cheltuială',
    'income' => 'Venit',

    'amount' => 'Sumă (:symbol)',
    'date' => 'Dată',
    'counterparty' => 'Contraparte',
    'counterparty_placeholder' => 'ex. Brutărie',
    'category' => 'Categorie',
    'optional' => '(opțional)',
    'uncategorized' => 'Necategorizat',
    'note' => 'Notă',

    'add_entry' => 'Adaugă intrare',
    'manual_entries' => 'Intrări manuale',
    'no_entries' => 'Încă nu există intrări manuale.',
    'delete_entry' => 'Șterge intrarea',
    'delete_entry_caption' => 'Șterge',
    'delete' => 'Șterge',
    'delete_confirm' => 'Ștergeți această înregistrare?',
    'delete_keep' => 'Păstrează',

    'errors' => [
        'amount_positive' => 'Introdu o sumă mai mare decât zero.',
        'amount_too_large' => 'Suma este prea mare. Verifică cifrele.',
        'amount_unreadable' => 'Suma nu a putut fi citită. Introdu-o cu cel mult :decimals zecimală, de exemplu :example.|Suma nu a putut fi citită. Introdu-o cu cel mult :decimals zecimale, de exemplu :example.|Suma nu a putut fi citită. Introdu-o cu cel mult :decimals de zecimale, de exemplu :example.',
        'amount_unreadable_whole' => 'Suma nu a putut fi citită. Această monedă nu are zecimale, așa că introdu un număr întreg, de exemplu :example.',
        'invalid_date' => 'Introdu o dată validă.',
        'not_recorded' => 'Înregistrarea nu a fost salvată. Încearcă să o adaugi din nou.',
    ],

    'toast' => [
        'added' => 'Intrare de numerar adăugată.',
        'removed' => 'Intrare de numerar ștearsă.',
        'reconciled_locked' => 'Această tranzacție este reconciliată. Anulează reconcilierea pentru a face modificări.',
    ],
];
