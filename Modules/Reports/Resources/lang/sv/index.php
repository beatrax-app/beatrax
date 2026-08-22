<?php

declare(strict_types=1);

return [
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'saved_report' => ':count sparad rapport|:count sparade rapporter',
    'pinned_count' => ':count av :max fäst|:count av :max fästa',
    'dismiss' => 'Stäng',

    'build_new' => 'Skapa en ny rapport',
    'view_mode_aria' => 'Visningsläge',
    'cards' => 'Kort',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Inga sparade rapporter ännu',
        'body' => 'Skapa en nedan och spara den så visas den här.',
        'cta' => 'Skapa din första rapport →',
    ],

    'pin' => [
        'pinned_aria' => 'Fäst — lossa från översikten',
        'pin_aria' => 'Fäst — fäst på översikten',
        'pinned_title' => 'Fäst',
        'pin_title' => 'Fäst på översikten',
        'pinned_label' => 'Fäst',
        'pin_label' => 'Fäst',
    ],

    'open' => 'Öppna',
    'edit' => 'Redigera',

    'delete_confirm' => 'Ta bort ":name"?',
    'delete_report' => 'Ta bort rapporten',
    'cancel' => 'Avbryt',
    'delete' => 'Ta bort',
    'delete_aria' => 'Ta bort :name',

    'col' => [
        'name' => 'Namn',
        'summary' => 'Sammanfattning',
        'pinned' => 'Fäst',
        'actions' => 'Åtgärder',
    ],

    'flash' => [
        'not_found' => 'Rapporten hittades inte (den kan ha tagits bort i en annan flik).',
        'deleted' => 'Rapporten borttagen.',
    ],
    'pin_cap' => 'Du kan fästa :max rapport. Lossa den för att lägga till den här.|Du kan fästa upp till :max rapporter. Lossa en för att lägga till den här.',

    'summary' => [
        'metric' => [
            'spend' => 'Utgifter',
            'income' => 'Inkomster',
            'net' => 'Netto',
            'net_worth' => 'Nettoförmögenhet',
            'fallback' => 'Belopp',
        ],
        'dimension' => [
            'category' => 'kategori',
            'time_bucket' => 'tidsintervall',
            'counterparty' => 'motpart',
            'account' => 'konto',
            'fallback' => 'kategori',
        ],
        'period' => [
            'this_month' => 'Den här månaden',
            'last_3_months' => 'Senaste 3 månaderna',
            'last_6_months' => 'Senaste 6 månaderna',
            'last_12_months' => 'Senaste 12 månaderna',
            'ytd' => 'Hittills i år',
            'this_year' => 'I år',
            'custom' => 'Anpassat intervall',
        ],
        'with_dimension' => ':metric · efter :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
