<?php

declare(strict_types=1);

return [
    'page_title' => 'Pokladničná kniha',
    'heading' => 'Pokladničná kniha',
    'intro' => 'Zaznamenávaj hotovosť a ďalšie výdavky mimo banky ručne. Ručné záznamy idú do tej istej knihy ako importy — kategorizujú sa, priradia sa k protistrane, zisťuje sa v nich opakovanie a rátajú sa do tvojho mesiaca.',

    'direction' => 'Smer',
    'expense' => 'Výdavok',
    'income' => 'Príjem',

    'amount' => 'Suma (:symbol)',
    'date' => 'Dátum',
    'counterparty' => 'Protistrana',
    'counterparty_placeholder' => 'napr. Pekáreň',
    'category' => 'Kategória',
    'optional' => '(nepovinné)',
    'uncategorized' => 'Bez kategórie',
    'note' => 'Poznámka',

    'add_entry' => 'Pridať záznam',
    'manual_entries' => 'Ručné záznamy',
    'no_entries' => 'Zatiaľ žiadne ručné záznamy.',
    'delete_entry' => 'Odstrániť záznam',
    'delete_entry_caption' => 'Odstrániť',
    'delete' => 'Odstrániť',
    'delete_confirm' => 'Zmazať túto položku?',
    'delete_keep' => 'Ponechať',

    'errors' => [
        'amount_positive' => 'Zadaj sumu väčšiu ako nula.',
        'amount_too_large' => 'Táto suma je príliš veľká. Skontroluj číslice.',
        'amount_unreadable' => 'Sumu sa nepodarilo prečítať. Zadaj ju najviac na :decimals desatinné miesto, napríklad :example.|Sumu sa nepodarilo prečítať. Zadaj ju najviac na :decimals desatinné miesta, napríklad :example.|Sumu sa nepodarilo prečítať. Zadaj ju najviac na :decimals desatinných miest, napríklad :example.',
        'amount_unreadable_whole' => 'Sumu sa nepodarilo prečítať. Táto mena nemá desatinné miesta, zadaj teda celé číslo, napríklad :example.',
        'invalid_date' => 'Zadaj platný dátum.',
        'not_recorded' => 'Záznam sa neuložil. Skús ho pridať znova.',
    ],

    'toast' => [
        'added' => 'Hotovostný záznam pridaný.',
        'removed' => 'Hotovostný záznam odstránený.',
        'reconciled_locked' => 'Táto transakcia je odsúhlasená. Ak ju chceš zmeniť, najprv zruš odsúhlasenie.',
    ],
];
