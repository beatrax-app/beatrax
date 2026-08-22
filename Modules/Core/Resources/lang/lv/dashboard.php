<?php

declare(strict_types=1);

return [
    'page_title' => 'Pārskats',
    'subtitle' => 'Šis periods īsumā.',

    'previous_period' => 'Iepriekšējais periods',
    'today' => 'Šodien',
    'next_period' => 'Nākamais periods',

    'totals_aria' => 'Šī perioda kopsummas',
    'totals_aria_currency' => 'Šī perioda kopsummas — :currency',
    'in' => 'Ieņēmumi',
    'out' => 'Izdevumi',
    'net' => 'Neto',

    'status_tiles_aria' => 'Statusa elementi',
    // i18n-review: lv · email_scan_health — the participle in a colon label still
    // has to pick a number in Latvian, so it is inflected per arm; Czech and
    // Polish reach for an impersonal here. A header shape ("Pievienotās
    // pastkastes: :count") may read better and wants a native call.
    'email_scan_health' => 'E-pasta skenēšanas stāvoklis — pievienotas: :count pastkastu|E-pasta skenēšanas stāvoklis — pievienota: :count pastkaste|E-pasta skenēšanas stāvoklis — pievienotas: :count pastkastes',

    'top_spending' => 'Lielākie tēriņi',
    'no_expenses' => 'Vēl nav kategorizētu izdevumu.',

    'recent_transactions' => 'Jaunākie darījumi',
    'view_all' => 'Skatīt visus',
    'nothing_period' => 'Šajā periodā šeit nekā nav.',
    'th_date' => 'Datums',
    'th_counterparty' => 'Darījuma partneris',
    'th_category' => 'Kategorija',
    'th_amount' => 'Summa',
    'uncategorized' => 'Bez kategorijas',

    'reauth' => [
        'title' => 'Kāda pastkaste ir jāpievieno atkārtoti.',
        'body' => 'Viena vai vairākas pastkastes tika atteiktas — Beatrax nevar tās skenēt, kamēr tās nepievienosiet atkārtoti.',
        'link' => 'Doties uz pastkastēm',
        'dismiss' => 'Aizvērt',
    ],

    'failed_chain' => [
        'title' => 'Ķēžu atrisināšana neizdevās.',
        'body' => 'Vienā vai vairākos ķēžu atrisināšanas uzdevumos radās kļūda.',
        'link' => 'Atvērt rindas inspektoru',
    ],
];
