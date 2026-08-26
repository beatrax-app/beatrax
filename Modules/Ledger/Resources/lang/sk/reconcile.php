<?php

declare(strict_types=1);

return [
    'page_title' => 'Odsúhlasenie',
    'heading' => 'Odsúhlasenie',
    'intro' => 'Porovnaj zostatok podľa výpisu z účtu so svojimi zúčtovanými transakciami. Keď sa zhodujú, dokonči odsúhlasenie a tie riadky sa zamknú.',

    'account' => 'Účet',
    'choose_account' => 'Vyber účet…',
    'statement_date' => 'Dátum výpisu',
    'statement_balance' => 'Zostatok podľa výpisu (:symbol)',
    'balance_help' => 'Predvyplnené z posledného importovaného výpisu, ak je k dispozícii — záporné pri dlžnej sume, upraviť sa dá tak či tak.',

    'cleared_balance' => 'Zúčtovaný zostatok',
    'statement_target' => 'Cieľ podľa výpisu',
    'difference' => 'Rozdiel',

    'pill' => [
        'choose_account' => 'vyber účet',
        'enter_balance' => 'zadaj zostatok podľa výpisu',
        'matched' => 'zhoda — :amount',
        'discrepancy' => 'rozdiel — :amount',
    ],

    'mismatch_html' => 'Zostatok podľa výpisu sa zatiaľ nezhoduje s tvojím zúčtovaným zostatkom. Prepínaj zúčtované riadky v <a href=":url" class="underline">zozname transakcií</a> alebo uprav zadaný zostatok, kým rozdiel neklesne na nulu — tento postup nikdy nevytvára vyrovnávaciu položku.',

    'check' => 'Skontrolovať',
    'complete' => 'Dokončiť odsúhlasenie',

    'errors' => [
        'choose_account' => 'Najprv vyber účet.',
        'invalid_balance_date' => 'Zadaj platný zostatok podľa výpisu a dátum.',
        'mismatch' => 'Zostatok podľa výpisu sa zatiaľ nezhoduje so zúčtovaným zostatkom — uprav zúčtované riadky alebo zadaný zostatok, kým bude rozdiel nulový.',
    ],

    'toast' => [
        'nothing_to_lock' => 'K tomuto dátumu výpisu nie je čo zamknúť.',
        'complete' => 'Odsúhlasenie dokončené — zamknutý :count riadok.|Odsúhlasenie dokončené — zamknuté :count riadky.|Odsúhlasenie dokončené — zamknutých :count riadkov.',
    ],
];
