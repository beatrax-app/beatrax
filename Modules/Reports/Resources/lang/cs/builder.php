<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategorie',
    'no_counterparty' => 'Bez protistrany',
    'unavailable_counterparty' => 'Protistrana není v tomto zařízení',
    'title' => 'Sestavy',
    'page_title' => 'Sestavy · Beatrax',
    'subtitle' => 'Poskládej si sestavu ze své knihy.',
    'controls_aria' => 'Nastavení sestavy',
    'result_aria' => 'Výsledek sestavy',
    'dismiss' => 'Zamítnout',

    'metric' => [
        'heading' => 'Metrika',
        'spend' => 'Výdaje',
        'income' => 'Příjmy',
        'net' => 'Netto',
        'net_worth' => 'Čisté jmění',
        'fallback' => 'Částka',
    ],

    'group_by' => 'Seskupit podle',

    'dimension' => [
        'category' => 'Kategorie',
        'time_bucket' => 'Časový úsek',
        'counterparty' => 'Protistrana',
        'account' => 'Účet',
    ],

    'period' => [
        'heading' => 'Období',
        'this_month' => 'Tento měsíc',
        'last_3_months' => 'Poslední 3 měsíce',
        'last_6_months' => 'Posledních 6 měsíců',
        'last_12_months' => 'Posledních 12 měsíců',
        'ytd' => 'Od začátku roku',
        'this_year' => 'Tento rok',
        'custom' => 'Vlastní rozsah',
        'from' => 'Od',
        'to' => 'Do',
        'error' => [
            'incomplete' => 'Vyber počáteční i koncové datum.',
            'malformed' => 'Zadej platné datum ve tvaru RRRR-MM-DD.',
            'inverted' => 'Koncové datum je dříve než počáteční.',
        ],
    ],

    'currency' => [
        'heading' => 'Měna',
        'aria' => 'Režim měny',
        'base' => 'Základní',
        'original' => 'Původní',
    ],

    'granularity' => [
        'heading' => 'Granularita',
        'aria' => 'Časová granularita',
        'monthly' => 'Měsíčně',
        'weekly' => 'Týdně',
    ],

    'filters' => [
        'heading' => 'Filtry',
        'net_worth_note' => 'Čisté jmění je zůstatek: platí jen filtr účtu.',
    ],

    'compare' => 'Porovnat s předchozím obdobím',

    'viz' => [
        'heading' => 'Vizualizace',
        'table' => 'Tabulka',
        'bar' => 'Sloupcový',
        'line' => 'Spojnicový',
        'donut' => 'Prstencový',
    ],

    'actions' => [
        'update_report' => 'Aktualizovat sestavu',
        'save_report' => 'Uložit sestavu',
        'report_name' => 'Název sestavy',
        'update' => 'Aktualizovat',
        'save' => 'Uložit',
        'cancel' => 'Zrušit',
        'export_csv' => 'Exportovat CSV',
    ],

    'updating' => '… Aktualizuje se',

    'empty' => [
        'heading' => 'Pro tento výběr není co zobrazit',
        'body' => 'Zkus rozšířit rozsah dat nebo odebrat filtr.',
    ],

    'total_prefix' => 'Celkem',
    'total' => 'Celkem',
    'vs_previous' => 'oproti předchozímu období',
    'view_transactions' => 'Zobrazit transakce',

    'fx_excluded' => ':count účet nepřeveden — kurz není k dispozici|:count účty nepřevedeny — kurz není k dispozici|:count účtů nepřevedeno — kurz není k dispozici',

    'group_header' => [
        'category' => 'Kategorie',
        'counterparty' => 'Protistrana',
        'account' => 'Účet',
        'month' => 'Měsíc',
        'default' => 'Skupina',
    ],

    'chart' => [
        'other_currencies' => 'Graf v měně :currency — :list se nezobrazuje',
        'undrawn' => 'Není v prstenci — :amount jde opačným směrem',
        'bar_title' => 'Klikni na sloupec a zobraz jeho transakce',
        'line_title' => 'Klikni na bod a zobraz jeho transakce',
        'donut_title' => 'Klikni na segment a zobraz jeho transakce',
    ],

    'flash' => [
        'saved' => 'Sestava uložena.',
        'updated' => 'Sestava aktualizována.',
    ],

    'filter' => [
        'account' => 'Účet',
        'account_count' => ':count účet|:count účty|:count účtů',
        'remove_account' => 'Odebrat filtr účtu',
        'account_dialog' => 'Filtr účtů',

        'category' => 'Kategorie',
        'category_count' => ':count kategorie|:count kategorie|:count kategorií',
        'remove_category' => 'Odebrat filtr kategorie',
        'category_dialog' => 'Filtr kategorií',

        'counterparty' => 'Protistrana',
        'counterparty_count' => ':count protistrana|:count protistrany|:count protistran',
        'remove_counterparty' => 'Odebrat filtr protistrany',
        'counterparty_dialog' => 'Filtr protistran',

        'amount' => 'Částka',
        'remove_amount' => 'Odebrat filtr částky',
        'amount_dialog' => 'Filtr částky',
        'dir_both' => 'Obojí',
        'dir_in' => 'Příjmy',
        'dir_out' => 'Výdaje',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimální částka',
        'max_aria' => 'Maximální částka',
    ],

    'other_movement' => 'Poplatky a úpravy (nezapočteno výše)',
    'other_movement_with_refunds' => 'Poplatky, vratky a úpravy (nezapočteno výše)',
];
