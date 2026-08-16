<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhľad importu',
    'heading' => 'Náhľad importu',
    'discard' => 'Zahodiť import',
    'confirm' => 'Potvrdiť import',
    'subtitle' => 'Skontroluj načítané riadky. Do knihy sa nič neuloží, kým to nepotvrdíš.',

    'expired_html' => 'Náhľad expiroval. <a href="/imports/new" class="underline">Nahraj súbor znova</a> a skús to ešte raz.',

    'save_name' => 'Uložiť názov',
    'account_name_label' => 'Názov účtu',
    'account_placeholder' => 'napr. Hlavný sporiaci účet',
    'rename_aria' => 'Premenovať túto protistranu',

    'unknown_iban_prefix' => 'Našli sme neznámy IBAN:',
    'unknown_iban_suffix' => 'Pomenuj tento účet.',

    'ics' => [
        'heading' => 'Pomenuj svoj kartový účet ICS.',
        'help' => 'Toto je prvý import údajov ICS. Daj tejto karte názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. Karta ICS',
    ],

    'paypal' => [
        'heading' => 'Pomenuj svoj účet PayPal.',
        'help' => 'Toto je prvý import údajov z PayPalu. Daj tejto peňaženke názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. PayPal',
    ],

    'col_date' => 'Dátum',
    'col_funding_source' => 'Zdroj financovania',
    'col_counterparty' => 'Protistrana',
    'col_amount' => 'Suma',
    'col_status' => 'Stav',

    'status' => [
        'new' => 'Nová',
        'new_title' => 'Pridá sa do tvojej knihy.',
        'duplicate' => 'Duplikát',
        'duplicate_title' => 'Už importované — preskočí sa.',
        'enriched' => 'Doplnená',
        'enriched_title' => 'Existujúci riadok sa doplní o silnejší odkaz na zdroj.',
        'error' => 'Chyba',
    ],

    'chain' => [
        'heading' => 'Riešia sa reťazce…',
        'pending' => 'Vo fronte. Riešenie reťazcov sa čoskoro spustí.',
        'running' => 'Prepájajú sa reťazce financovania a rozkladajú sa zúčtovania z výpisu z účtu.',
        'failed_prefix' => 'Riešenie reťazcov zlyhalo:',
        'unknown_error' => 'došlo k neznámej chybe',
        'open_horizon' => 'Otvoriť Horizon',
        'failed_suffix' => 'na opakovanie alebo kontrolu.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Tento IBAN nie je súčasťou aktuálneho náhľadu.',
    ],
];
