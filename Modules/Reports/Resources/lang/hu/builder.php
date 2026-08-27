<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Kategorizálatlan',
    'title' => 'Jelentések',
    'page_title' => 'Jelentések · Beatrax',
    'subtitle' => 'Állíts össze jelentést a nyilvántartásodból.',
    'controls_aria' => 'Jelentés vezérlői',
    'result_aria' => 'Jelentés eredménye',
    'dismiss' => 'Elvetés',

    'metric' => [
        'heading' => 'Mérőszám',
        'spend' => 'Költés',
        'income' => 'Bevétel',
        'net' => 'Nettó',
        'net_worth' => 'Nettó vagyon',
        'fallback' => 'Összeg',
    ],

    'group_by' => 'Csoportosítás',

    'dimension' => [
        'category' => 'Kategória',
        'time_bucket' => 'Időszakos bontás',
        'counterparty' => 'Partner',
        'account' => 'Számla',
    ],

    'period' => [
        'heading' => 'Időszak',
        'this_month' => 'Ez a hónap',
        'last_3_months' => 'Elmúlt 3 hónap',
        'last_6_months' => 'Elmúlt 6 hónap',
        'last_12_months' => 'Elmúlt 12 hónap',
        'ytd' => 'Év eleje óta',
        'this_year' => 'Ez az év',
        'custom' => 'Egyéni tartomány',
        'from' => 'Ettől',
        'to' => 'Eddig',
        'error' => [
            'incomplete' => 'Válassz kezdő és záró dátumot is.',
            'malformed' => 'Adj meg érvényes dátumot ÉÉÉÉ-HH-NN formában.',
            'inverted' => 'A záró dátum korábbi a kezdőnél.',
        ],
    ],

    'currency' => [
        'heading' => 'Pénznem',
        'aria' => 'Pénznemmód',
        'base' => 'Alap',
        'original' => 'Eredeti',
    ],

    'granularity' => [
        'heading' => 'Részletesség',
        'aria' => 'Idő szerinti részletesség',
        'monthly' => 'Havi',
        'weekly' => 'Heti',
    ],

    'filters' => [
        'heading' => 'Szűrők',
        'net_worth_note' => 'A nettó vagyon egyenleg: csak a számlaszűrő érvényes.',
    ],

    'compare' => 'Összehasonlítás az előző időszakkal',

    'viz' => [
        'heading' => 'Megjelenítés',
        'table' => 'Táblázat',
        'bar' => 'Oszlop',
        'line' => 'Vonal',
        'donut' => 'Gyűrű',
    ],

    'actions' => [
        'update_report' => 'Jelentés frissítése',
        'save_report' => 'Jelentés mentése',
        'report_name' => 'Jelentés neve',
        'update' => 'Frissítés',
        'save' => 'Mentés',
        'cancel' => 'Mégse',
        'export_csv' => 'CSV exportálása',
    ],

    'updating' => '… Frissítés',

    'empty' => [
        'heading' => 'Ehhez a kiválasztáshoz nincs megjeleníthető adat',
        'body' => 'Próbáld bővíteni az időtartományt, vagy távolíts el egy szűrőt.',
    ],

    'total_prefix' => 'Összesen',
    'total' => 'Összesen',
    'vs_previous' => 'az előző időszakhoz képest',
    'view_transactions' => 'Tranzakciók megtekintése',

    'fx_excluded' => '{0} nincs átváltatlan számla — nincs elérhető árfolyam|[1,*] :count számla nincs átváltva — nincs elérhető árfolyam',

    'group_header' => [
        'category' => 'Kategória',
        'counterparty' => 'Partner',
        'account' => 'Számla',
        'month' => 'Hónap',
        'default' => 'Csoport',
    ],

    'chart' => [
        'bar_title' => 'Kattints egy oszlopra a tranzakciói megtekintéséhez',
        'line_title' => 'Kattints egy pontra a tranzakciói megtekintéséhez',
        'donut_title' => 'Kattints egy szegmensre a tranzakciói megtekintéséhez',
    ],

    'flash' => [
        'saved' => 'Jelentés mentve.',
        'updated' => 'Jelentés frissítve.',
    ],

    'filter' => [
        'account' => 'Számla',
        'account_count' => ':count számla|:count számla',
        'remove_account' => 'Számlaszűrő eltávolítása',
        'account_dialog' => 'Számlaszűrő',

        'category' => 'Kategória',
        'category_count' => ':count kategória|:count kategória',
        'remove_category' => 'Kategóriaszűrő eltávolítása',
        'category_dialog' => 'Kategóriaszűrő',

        'counterparty' => 'Partner',
        'counterparty_count' => ':count partner|:count partner',
        'remove_counterparty' => 'Partnerszűrő eltávolítása',
        'counterparty_dialog' => 'Partnerszűrő',

        'amount' => 'Összeg',
        'remove_amount' => 'Összegszűrő eltávolítása',
        'amount_dialog' => 'Összegszűrő',
        'dir_both' => 'Mindkettő',
        'dir_in' => 'Be',
        'dir_out' => 'Ki',
        'min' => 'Min.',
        'max' => 'Max.',
        'min_aria' => 'Minimális összeg',
        'max_aria' => 'Maximális összeg',
    ],

    'other_movement' => 'Díjak és korrekciók (a fentiben nem szerepel)',
    'other_movement_with_refunds' => 'Díjak, visszatérítések és korrekciók (a fentiben nem szerepel)',
];
