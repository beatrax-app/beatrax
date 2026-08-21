<?php

declare(strict_types=1);

return [
    'title' => 'Ataskaitos',
    'page_title' => 'Ataskaitos · Beatrax',
    'saved_report' => ':count išsaugota ataskaita|:count išsaugotos ataskaitos|:count išsaugotų ataskaitų',
    'pinned_count' => 'prisegta',
    'dismiss' => 'Slėpti',

    'build_new' => 'Sukurti naują ataskaitą',
    'view_mode_aria' => 'Rodinio režimas',
    'cards' => 'Kortelės',
    'list' => 'Sąrašas',

    'empty' => [
        'heading' => 'Išsaugotų ataskaitų dar nėra',
        'body' => 'Sukurk ataskaitą žemiau ir ją išsaugok, kad matytum čia.',
        'cta' => 'Sukurk pirmą ataskaitą →',
    ],

    'pin' => [
        'pinned_aria' => 'Prisegta — atsegti nuo apžvalgos',
        'pin_aria' => 'Prisegti — prisegti prie apžvalgos',
        'pinned_title' => 'Prisegta',
        'pin_title' => 'Prisegti prie apžvalgos',
        'pinned_label' => 'Prisegta',
        'pin_label' => 'Prisegti',
    ],

    'open' => 'Atverti',
    'edit' => 'Redaguoti',

    'delete_confirm' => 'Ištrinti „:name“?',
    'delete_report' => 'Ištrinti ataskaitą',
    'cancel' => 'Atšaukti',
    'delete' => 'Ištrinti',
    'delete_aria' => 'Ištrinti :name',

    'col' => [
        'name' => 'Pavadinimas',
        'summary' => 'Santrauka',
        'pinned' => 'Prisegta',
        'actions' => 'Veiksmai',
    ],

    'flash' => [
        'not_found' => 'Ataskaita nerasta (galbūt ji ištrinta kitoje kortelėje).',
        'deleted' => 'Ataskaita ištrinta.',
    ],
    'pin_cap' => 'Gali prisegti iki 3 ataskaitų. Kad pridėtum šią, vieną atsek.',

    'summary' => [
        'metric' => [
            'spend' => 'Išlaidos',
            'income' => 'Pajamos',
            'net' => 'Grynasis',
            'net_worth' => 'Grynoji vertė',
            'fallback' => 'Suma',
        ],
        'dimension' => [
            'category' => 'kategoriją',
            'time_bucket' => 'laiko intervalą',
            'counterparty' => 'kitą šalį',
            'account' => 'sąskaitą',
            'fallback' => 'kategoriją',
        ],
        'period' => [
            'this_month' => 'Šis mėnuo',
            'last_3_months' => 'Paskutiniai 3 mėnesiai',
            'last_6_months' => 'Paskutiniai 6 mėnesiai',
            'last_12_months' => 'Paskutiniai 12 mėnesių',
            'ytd' => 'Nuo metų pradžios',
            'this_year' => 'Šie metai',
            'custom' => 'Pasirinktas laikotarpis',
        ],
        'with_dimension' => ':metric · pagal :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
