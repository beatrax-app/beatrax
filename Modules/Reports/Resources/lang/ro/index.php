<?php

declare(strict_types=1);

return [
    'title' => 'Rapoarte',
    'page_title' => 'Rapoarte · Beatrax',
    'saved_report' => 'raport salvat|rapoarte salvate|de rapoarte salvate',
    'pinned_count' => 'fixate',
    'dismiss' => 'Închide',

    'build_new' => 'Creează un raport nou',
    'view_mode_aria' => 'Mod de vizualizare',
    'cards' => 'Carduri',
    'list' => 'Listă',

    'empty' => [
        'heading' => 'Niciun raport salvat deocamdată',
        'body' => 'Creează unul mai jos și salvează-l ca să apară aici.',
        'cta' => 'Creează primul tău raport →',
    ],

    'pin' => [
        'pinned_aria' => 'Fixat — anulează fixarea pe tabloul de bord',
        'pin_aria' => 'Fixează — fixează pe tabloul de bord',
        'pinned_title' => 'Fixat',
        'pin_title' => 'Fixează pe tabloul de bord',
        'pinned_label' => 'Fixat',
        'pin_label' => 'Fixează',
    ],

    'open' => 'Deschide',
    'edit' => 'Editează',

    'delete_confirm' => 'Ștergi „:name”?',
    'delete_report' => 'Șterge raportul',
    'cancel' => 'Anulează',
    'delete' => 'Șterge',
    'delete_aria' => 'Șterge :name',

    'col' => [
        'name' => 'Nume',
        'summary' => 'Sumar',
        'pinned' => 'Fixat',
        'actions' => 'Acțiuni',
    ],

    'flash' => [
        'not_found' => 'Raportul nu a fost găsit (poate a fost șters în altă filă).',
        'deleted' => 'Raport șters.',
    ],
    'pin_cap' => 'Poți fixa cel mult 3 rapoarte. Anulează fixarea unuia ca să îl adaugi pe acesta.',

    'summary' => [
        'metric' => [
            'spend' => 'Cheltuieli',
            'income' => 'Venituri',
            'net' => 'Net',
            'net_worth' => 'Patrimoniu net',
            'fallback' => 'Sumă',
        ],
        'dimension' => [
            'category' => 'categorie',
            'time_bucket' => 'interval de timp',
            'counterparty' => 'contraparte',
            'account' => 'cont',
            'fallback' => 'categorie',
        ],
        'period' => [
            'this_month' => 'Luna aceasta',
            'last_3_months' => 'Ultimele 3 luni',
            'last_6_months' => 'Ultimele 6 luni',
            'last_12_months' => 'Ultimele 12 luni',
            'ytd' => 'De la începutul anului',
            'this_year' => 'Anul acesta',
            'custom' => 'Interval personalizat',
        ],
        'with_dimension' => ':metric · după :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
