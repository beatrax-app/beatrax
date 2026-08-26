<?php

declare(strict_types=1);

return [
    'page_title' => 'Pokladničná kniha',
    'heading' => 'Pokladničná kniha',
    'intro' => 'Zaznamenávaj hotovosť a ďalšie výdavky mimo banky ručne. Ručné záznamy idú do tej istej knihy ako importy — kategorizujú sa, zisťuje sa v nich opakovanie a rátajú sa do tvojho mesiaca.',

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
    'delete' => 'Odstrániť',
    'delete_confirm' => 'Zmazať túto položku?',
    'delete_keep' => 'Ponechať',

    'errors' => [
        'amount_positive' => 'Zadaj sumu väčšiu ako nula.',
        'amount_too_large' => 'Táto suma je príliš veľká. Skontroluj číslice.',
        'amount_unreadable' => 'Túto sumu sa nepodarilo prečítať. Zadajte ju bez oddeľovača tisícov a najviac s dvoma desatinnými miestami, napríklad :example.',
        'invalid_date' => 'Zadaj platný dátum.',
    ],

    'toast' => [
        'added' => 'Hotovostný záznam pridaný.',
        'removed' => 'Hotovostný záznam odstránený.',
    ],
];
