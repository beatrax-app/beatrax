<?php

declare(strict_types=1);

return [
    'title' => 'Atskaites',
    'page_title' => 'Atskaites · Beatrax',
    'saved_report' => 'saglabātu atskaišu|saglabāta atskaite|saglabātas atskaites',
    'pinned_count' => 'piespraustas',
    'dismiss' => 'Aizvērt',

    'build_new' => 'Izveidot jaunu atskaiti',
    'view_mode_aria' => 'Skata režīms',
    'cards' => 'Kartītes',
    'list' => 'Saraksts',

    'empty' => [
        'heading' => 'Vēl nav saglabātu atskaišu',
        'body' => 'Izveidojiet atskaiti zemāk un saglabājiet to, lai tā parādītos šeit.',
        'cta' => 'Izveidojiet savu pirmo atskaiti →',
    ],

    'pin' => [
        'pinned_aria' => 'Piesprausta — atspraust no pārskata',
        'pin_aria' => 'Piespraust — piespraust pārskatam',
        'pinned_title' => 'Piesprausta',
        'pin_title' => 'Piespraust pārskatam',
        'pinned_label' => 'Piesprausta',
        'pin_label' => 'Piespraust',
    ],

    'open' => 'Atvērt',
    'edit' => 'Rediģēt',

    'delete_confirm' => 'Dzēst „:name”?',
    'delete_report' => 'Dzēst atskaiti',
    'cancel' => 'Atcelt',
    'delete' => 'Dzēst',
    'delete_aria' => 'Dzēst: :name',

    'col' => [
        'name' => 'Nosaukums',
        'summary' => 'Kopsavilkums',
        'pinned' => 'Piesprausta',
        'actions' => 'Darbības',
    ],

    'flash' => [
        'not_found' => 'Atskaite nav atrasta (iespējams, tā ir izdzēsta citā cilnē).',
        'deleted' => 'Atskaite izdzēsta.',
    ],
    'pin_cap' => 'Varat piespraust līdz 3 atskaitēm. Atspraužiet kādu, lai pievienotu šo.',

    'summary' => [
        'metric' => [
            'spend' => 'Tēriņi',
            'income' => 'Ieņēmumi',
            'net' => 'Neto',
            'net_worth' => 'Neto vērtība',
            'fallback' => 'Summa',
        ],
        'dimension' => [
            'category' => 'kategorija',
            'time_bucket' => 'laika intervāls',
            'counterparty' => 'darījuma partneris',
            'account' => 'konts',
            'fallback' => 'kategorija',
        ],
        'period' => [
            'this_month' => 'Šis mēnesis',
            'last_3_months' => 'Pēdējie 3 mēneši',
            'last_6_months' => 'Pēdējie 6 mēneši',
            'last_12_months' => 'Pēdējie 12 mēneši',
            'ytd' => 'Kopš gada sākuma',
            'this_year' => 'Šis gads',
            'custom' => 'Pielāgots periods',
        ],
        'with_dimension' => ':metric · grupēts pēc: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
