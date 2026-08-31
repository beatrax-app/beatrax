<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Contul tău PayPal',
    'h1' => 'Conectează-ți contul PayPal',

    'lede_html' => 'Trage aici exportul de tranzacții PayPal — un rând pe tranzacție, nu rezumatul soldului. PayPal își denumește rapoartele în limba contului tău, iar deocamdată citim perechea neerlandeză: <em lang="nl">Rapport Transactiegegevens</em>, nu <span lang="nl">Saldorapport</span>. Dacă al tău iese în altă limbă, comută PayPal pe neerlandeză înainte de descărcare.',

    'format_group_aria' => 'PayPal exportă doar în CSV',
    'got_it_as' => 'L-ai primit ca:',
    'badge_only_format' => 'singurul format',

    'mini' => [
        'login_label' => 'Autentifică-te',
        'custom_label' => 'Extrase personalizate',
        'range_label' => 'Alege o perioadă',
        'range_sub' => 'Ultimele 12 luni',
        'download_label' => 'Descarcă drept CSV',
    ],

    'drop_lead' => 'Trage aici exportul tău de tranzacții',
    'browse_file' => 'sau caută un fișier',

    'file_ready' => '· ✓ gata',

    'skip' => 'Omite acest pas',
    'continue' => 'Continuă →',

    'errors' => [
        'required' => 'Trage mai întâi în casetă exportul de tranzacții PayPal.',
        'max' => 'Fișierul este prea mare. Un export de tranzacții PayPal este de obicei mult sub 10 MB.',
        'extensions' => 'Fișierul nu pare a fi un CSV de la PayPal. Descarcă exportul de tranzacții — un rând pe tranzacție, nu rezumatul soldului — în format CSV.',
        'unreadable' => 'Fișierul nu a putut fi citit. Eroarea completă este în /dev/logs.',
    ],
];
