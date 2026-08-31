<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Necategorizat',
    'no_counterparty' => 'Fără contraparte',
    'unavailable_counterparty' => 'Contrapartea nu există pe acest dispozitiv',
    'title' => 'Rapoarte',
    'page_title' => 'Rapoarte · Beatrax',
    'subtitle' => 'Compune un raport din registrul tău.',
    'controls_aria' => 'Comenzi pentru raport',
    'result_aria' => 'Rezultatul raportului',
    'dismiss' => 'Închide',

    'metric' => [
        'heading' => 'Indicator',
        'spend' => 'Cheltuieli',
        'income' => 'Venituri',
        'net' => 'Net',
        'net_worth' => 'Patrimoniu net',
        'fallback' => 'Sumă',
    ],

    'group_by' => 'Grupează după',

    'dimension' => [
        'category' => 'Categorie',
        'time_bucket' => 'Interval de timp',
        'counterparty' => 'Contraparte',
        'account' => 'Cont',
    ],

    'period' => [
        'heading' => 'Perioadă',
        'this_month' => 'Luna aceasta',
        'last_3_months' => 'Ultimele 3 luni',
        'last_6_months' => 'Ultimele 6 luni',
        'last_12_months' => 'Ultimele 12 luni',
        'ytd' => 'De la începutul anului',
        'this_year' => 'Anul acesta',
        'custom' => 'Interval personalizat',
        'from' => 'De la',
        'to' => 'Până la',
        'error' => [
            'incomplete' => 'Alege atât o dată de început, cât și una de sfârșit.',
            'malformed' => 'Folosește o dată validă în formatul AAAA-LL-ZZ.',
            'inverted' => 'Data de sfârșit este înaintea celei de început.',
        ],
    ],

    'currency' => [
        'heading' => 'Monedă',
        'aria' => 'Mod monedă',
        'base' => 'De bază',
        'original' => 'Originală',
    ],

    'granularity' => [
        'heading' => 'Granularitate',
        'aria' => 'Granularitate temporală',
        'monthly' => 'Lunar',
        'weekly' => 'Săptămânal',
    ],

    'filters' => [
        'heading' => 'Filtre',
        'net_worth_note' => 'Valoarea netă este un sold: se aplică doar filtrul de cont.',
    ],

    'compare' => 'Compară cu perioada anterioară',

    'viz' => [
        'heading' => 'Vizualizare',
        'table' => 'Tabel',
        'bar' => 'Bare',
        'line' => 'Linie',
        'donut' => 'Inel',
    ],

    'actions' => [
        'update_report' => 'Actualizează raportul',
        'save_report' => 'Salvează raportul',
        'report_name' => 'Numele raportului',
        'update' => 'Actualizează',
        'save' => 'Salvează',
        'cancel' => 'Anulează',
        'export_csv' => 'Exportă CSV',
    ],

    'updating' => '… Se actualizează',

    'empty' => [
        'heading' => 'Nimic de afișat pentru această selecție',
        'body' => 'Încearcă să extinzi intervalul de date sau să elimini un filtru.',
    ],

    'total_prefix' => 'Total',
    'total' => 'Total',
    'vs_previous' => 'față de perioada anterioară',
    'view_transactions' => 'Vezi tranzacțiile',

    'fx_excluded' => ':count cont neconvertit — niciun curs disponibil|:count conturi neconvertite — niciun curs disponibil|:count de conturi neconvertite — niciun curs disponibil',

    'group_header' => [
        'category' => 'Categorie',
        'counterparty' => 'Contraparte',
        'account' => 'Cont',
        'month' => 'Lună',
        'default' => 'Grup',
    ],

    'chart' => [
        'other_currencies' => 'Grafic în :currency — :list nu este reprezentat',
        'undrawn' => 'În afara inelului — :amount merge în sens invers',
        'bar_title' => 'Dă clic pe o bară pentru a vedea tranzacțiile ei',
        'line_title' => 'Dă clic pe un punct pentru a vedea tranzacțiile lui',
        'donut_title' => 'Dă clic pe un segment pentru a vedea tranzacțiile lui',
    ],

    'flash' => [
        'saved' => 'Raport salvat.',
        'updated' => 'Raport actualizat.',
    ],

    'filter' => [
        'account' => 'Cont',
        'account_count' => ':count cont|:count conturi|:count de conturi',
        'remove_account' => 'Elimină filtrul de cont',
        'account_dialog' => 'Filtru de cont',

        'category' => 'Categorie',
        'category_count' => ':count categorie|:count categorii|:count de categorii',
        'remove_category' => 'Elimină filtrul de categorie',
        'category_dialog' => 'Filtru de categorie',

        'counterparty' => 'Contraparte',
        'counterparty_count' => ':count contraparte|:count contrapărți|:count de contrapărți',
        'remove_counterparty' => 'Elimină filtrul de contraparte',
        'counterparty_dialog' => 'Filtru de contraparte',

        'amount' => 'Sumă',
        'remove_amount' => 'Elimină filtrul de sumă',
        'amount_dialog' => 'Filtru de sumă',
        'dir_both' => 'Ambele',
        'dir_in' => 'Intrări',
        'dir_out' => 'Ieșiri',
        'min' => 'Min.',
        'max' => 'Max.',
        'min_aria' => 'Sumă minimă',
        'max_aria' => 'Sumă maximă',
    ],

    'other_movement' => 'Comisioane și ajustări (necontabilizate mai sus)',
    'other_movement_with_refunds' => 'Comisioane, rambursări și ajustări (necontabilizate mai sus)',
];
