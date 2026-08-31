<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Brez kategorije',
    'no_counterparty' => 'Brez nasprotne stranke',
    'unavailable_counterparty' => 'Nasprotne stranke v tej napravi ni',
    'title' => 'Poročila',
    'page_title' => 'Poročila · Beatrax',
    'subtitle' => 'Sestavi poročilo iz svoje glavne knjige.',
    'controls_aria' => 'Nastavitve poročila',
    'result_aria' => 'Rezultat poročila',
    'dismiss' => 'Opusti',

    'metric' => [
        'heading' => 'Merilo',
        'spend' => 'Poraba',
        'income' => 'Prihodki',
        'net' => 'Neto',
        'net_worth' => 'Neto vrednost',
        'fallback' => 'Znesek',
    ],

    'group_by' => 'Združi po',

    'dimension' => [
        'category' => 'Kategorija',
        'time_bucket' => 'Časovni interval',
        'counterparty' => 'Nasprotna stranka',
        'account' => 'Račun',
    ],

    'period' => [
        'heading' => 'Obdobje',
        'this_month' => 'Ta mesec',
        'last_3_months' => 'Zadnji 3 meseci',
        'last_6_months' => 'Zadnjih 6 mesecev',
        'last_12_months' => 'Zadnjih 12 mesecev',
        'ytd' => 'Od začetka leta',
        'this_year' => 'To leto',
        'custom' => 'Poljuben razpon',
        'from' => 'Od',
        'to' => 'Do',
        'error' => [
            'incomplete' => 'Izberi tako začetni kot končni datum.',
            'malformed' => 'Vnesi veljaven datum v obliki LLLL-MM-DD.',
            'inverted' => 'Končni datum je pred začetnim.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Način prikaza valute',
        'base' => 'Osnovna',
        'original' => 'Izvirna',
    ],

    'granularity' => [
        'heading' => 'Raven podrobnosti',
        'aria' => 'Časovna raven podrobnosti',
        'monthly' => 'Mesečno',
        'weekly' => 'Tedensko',
    ],

    'filters' => [
        'heading' => 'Filtri',
        'net_worth_note' => 'Neto vrednost je stanje: velja samo filter računa.',
    ],

    'compare' => 'Primerjaj s prejšnjim obdobjem',

    'viz' => [
        'heading' => 'Vizualizacija',
        'table' => 'Tabela',
        'bar' => 'Stolpčni',
        'line' => 'Črtni',
        'donut' => 'Kolobarni',
    ],

    'actions' => [
        'update_report' => 'Posodobi poročilo',
        'save_report' => 'Shrani poročilo',
        'report_name' => 'Ime poročila',
        'update' => 'Posodobi',
        'save' => 'Shrani',
        'cancel' => 'Prekliči',
        'export_csv' => 'Izvozi CSV',
    ],

    'updating' => '… Posodabljanje',

    'empty' => [
        'heading' => 'Za ta izbor ni česa prikazati',
        'body' => 'Poskusi razširiti razpon datumov ali odstraniti kakšen filter.',
    ],

    'total_prefix' => 'Skupaj',
    'total' => 'Skupaj',
    'vs_previous' => 'v primerjavi s prejšnjim obdobjem',
    'view_transactions' => 'Prikaži transakcije',

    'fx_excluded' => ':count račun ni preračunan — tečaj ni na voljo|:count računa nista preračunana — tečaj ni na voljo|:count računi niso preračunani — tečaj ni na voljo|:count računov ni preračunanih — tečaj ni na voljo',

    'group_header' => [
        'category' => 'Kategorija',
        'counterparty' => 'Nasprotna stranka',
        'account' => 'Račun',
        'month' => 'Mesec',
        'default' => 'Skupina',
    ],

    'chart' => [
        'other_currencies' => 'Graf v valuti :currency — :list ni prikazano',
        'undrawn' => 'Ni v obroču — :amount gre v nasprotno smer',
        'bar_title' => 'Klikni stolpec za prikaz njegovih transakcij',
        'line_title' => 'Klikni točko za prikaz njenih transakcij',
        'donut_title' => 'Klikni segment za prikaz njegovih transakcij',
    ],

    'flash' => [
        'saved' => 'Poročilo je shranjeno.',
        'updated' => 'Poročilo je posodobljeno.',
    ],

    'filter' => [
        'account' => 'Račun',
        'account_count' => ':count račun|:count računa|:count računi|:count računov',
        'remove_account' => 'Odstrani filter računa',
        'account_dialog' => 'Filter računa',

        'category' => 'Kategorija',
        'category_count' => ':count kategorija|:count kategoriji|:count kategorije|:count kategorij',
        'remove_category' => 'Odstrani filter kategorije',
        'category_dialog' => 'Filter kategorije',

        'counterparty' => 'Nasprotna stranka',
        'counterparty_count' => ':count nasprotna stranka|:count nasprotni stranki|:count nasprotne stranke|:count nasprotnih strank',
        'remove_counterparty' => 'Odstrani filter nasprotne stranke',
        'counterparty_dialog' => 'Filter nasprotne stranke',

        'amount' => 'Znesek',
        'remove_amount' => 'Odstrani filter zneska',
        'amount_dialog' => 'Filter zneska',
        'dir_both' => 'Oboje',
        'dir_in' => 'Priliv',
        'dir_out' => 'Odliv',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanjši znesek',
        'max_aria' => 'Največji znesek',
    ],

    'other_movement' => 'Provizije in prilagoditve (niso vštete)',
    'other_movement_with_refunds' => 'Provizije, vračila in prilagoditve (niso vštete)',
];
