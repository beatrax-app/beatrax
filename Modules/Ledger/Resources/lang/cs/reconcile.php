<?php

declare(strict_types=1);

return [
    'page_title' => 'Odsouhlasení',
    'heading' => 'Odsouhlasení',
    'intro' => 'Potvrď zůstatek podle výpisu z účtu proti svým zúčtovaným transakcím. Když sedí, dokonči odsouhlasení a tyto řádky se uzamknou.',

    'account' => 'Účet',
    'choose_account' => 'Vyber účet…',
    'statement_date' => 'Datum výpisu',
    'statement_balance' => 'Zůstatek podle výpisu (:symbol)',
    'balance_help' => 'Předvyplní se z posledního naimportovaného výpisu, pokud je k dispozici — záporný u dluhu, v obou případech se dá upravit.',

    'cleared_balance' => 'Zúčtovaný zůstatek',
    'statement_target' => 'Cíl podle výpisu',
    'difference' => 'Rozdíl',

    'pill' => [
        'choose_account' => 'vyber účet',
        'enter_balance' => 'zadej zůstatek podle výpisu',
        'matched' => 'sedí — :amount',
        'discrepancy' => 'rozdíl — :amount',
    ],

    'mismatch_html' => 'Zůstatek podle výpisu zatím neodpovídá tvému zúčtovanému zůstatku. Přepínej zúčtované řádky v <a href=":url" class="underline">seznamu transakcí</a> nebo uprav zadaný zůstatek, dokud rozdíl nebude nula — tenhle postup nikdy nevytváří vyrovnávací zápis.',

    'check' => 'Zkontrolovat',
    'complete' => 'Dokončit odsouhlasení',

    'errors' => [
        'choose_account' => 'Nejdřív vyber účet.',
        'invalid_balance_date' => 'Zadej platný zůstatek podle výpisu a datum.',
        'mismatch' => 'Zůstatek podle výpisu zatím neodpovídá zúčtovanému zůstatku — uprav zúčtované řádky nebo zadaný zůstatek, dokud nebude rozdíl nulový.',
    ],

    'toast' => [
        'nothing_to_lock' => 'K tomuto datu výpisu není co uzamknout.',
        'complete' => 'Odsouhlasení dokončeno — uzamčen :count řádek.|Odsouhlasení dokončeno — uzamčeny :count řádky.|Odsouhlasení dokončeno — uzamčeno :count řádků.',
    ],
];
