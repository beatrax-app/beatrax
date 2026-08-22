<?php

declare(strict_types=1);

return [
    'title' => 'Rapoarte',
    'page_title' => 'Rapoarte · Beatrax',
    'saved_report' => ':count raport salvat|:count rapoarte salvate|:count de rapoarte salvate',
    // i18n-review: ro · pinned_count — the third arm is what Romanian selects from 20
    // up, which the pin cap keeps out of reach, so it repeats the second rather than
    // guessing at a "de" nobody can read. It wants a native eye if the cap ever grows.
    'pinned_count' => ':count din :max fixat|:count din :max fixate|:count din :max fixate',
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
    'pin_cap' => 'Poți fixa :max raport. Anulează fixarea lui ca să îl adaugi pe acesta.|Poți fixa cel mult :max rapoarte. Anulează fixarea unuia ca să îl adaugi pe acesta.|Poți fixa cel mult :max de rapoarte. Anulează fixarea unuia ca să îl adaugi pe acesta.',

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
