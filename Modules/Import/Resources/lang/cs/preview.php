<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhled importu',
    'heading' => 'Náhled importu',
    'discard' => 'Zahodit import',
    'confirm' => 'Potvrdit import',
    'subtitle' => 'Zkontroluj načtené řádky. Do knihy se nic neuloží, dokud to nepotvrdíš.',

    'expired_html' => 'Náhled vypršel. <a href="/imports/new" class="underline">Nahraj soubor znovu</a> a zkus to ještě jednou.',

    'save_name' => 'Uložit název',
    'account_name_label' => 'Název účtu',
    'account_placeholder' => 'např. Hlavní spořicí účet',
    'rename_aria' => 'Přejmenovat tuto protistranu',

    'unknown_iban_prefix' => 'Našli jsme neznámý IBAN:',
    'unknown_iban_suffix' => 'Pojmenuj tento účet.',

    'ics' => [
        'heading' => 'Pojmenuj svůj účet karty ICS.',
        'help' => 'Data z ICS importuješ poprvé. Dej téhle kartě název, ať se v celé aplikaci zobrazuje jednotně.',
        'placeholder' => 'např. Karta ICS',
    ],

    'paypal' => [
        'heading' => 'Pojmenuj svůj účet PayPal.',
        'help' => 'Data z PayPalu importuješ poprvé. Dej téhle peněžence název, ať se v celé aplikaci zobrazuje jednotně.',
        'placeholder' => 'např. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Zdroj financování',
    'col_counterparty' => 'Protistrana',
    'col_amount' => 'Částka',
    'col_status' => 'Stav',

    'status' => [
        'new' => 'Nová',
        'new_title' => 'Přidá se do tvé knihy.',
        'duplicate' => 'Duplicita',
        'duplicate_title' => 'Už naimportováno — přeskočí se.',
        'enriched' => 'Doplněna',
        'enriched_title' => 'Stávající řádek se doplní o silnější odkaz na zdroj.',
        'error' => 'Chyba',
    ],

    'chain' => [
        'heading' => 'Řeší se řetězce…',
        'pending' => 'Ve frontě. Řešení řetězců se brzy spustí.',
        'running' => 'Propojují se řetězce financování a rozkládají se vyrovnání z výpisu z účtu.',
        'failed_prefix' => 'Řešení řetězců selhalo:',
        'unknown_error' => 'došlo k neznámé chybě',
        'open_horizon' => 'Otevřít Horizon',
        'failed_suffix' => 'a zkus to znovu nebo se podívej, co se stalo.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Tento IBAN není součástí aktuálního náhledu.',
    ],
];
