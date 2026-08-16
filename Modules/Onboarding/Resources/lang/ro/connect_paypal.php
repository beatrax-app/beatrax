<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Contul tău PayPal',
    'h1' => 'Conectează-ți contul PayPal',

    'lede_html' => 'Trage aici exportul PayPal cu detaliile tranzacțiilor — într-un cont PayPal olandez apare ca <em lang="nl">Rapport Transactiegegevens</em>. Raportul de sold (<span lang="nl">Saldorapport</span>) nu funcționează — avem nevoie de date pentru fiecare eveniment.',

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

    'drop_lead' => 'Trage aici CSV-ul cu detaliile tranzacțiilor',
    'browse_file' => 'sau caută un fișier',

    'file_ready' => '· ✓ gata',

    'skip' => 'Omite acest pas',
    'continue' => 'Continuă →',

    'errors' => [
        'required' => 'Trage mai întâi în casetă CSV-ul PayPal Rapport Transactiegegevens.',
        'max' => 'Fișierul este prea mare. Exporturile PayPal Rapport Transactiegegevens sunt de obicei mult sub 10 MB.',
        'extensions' => 'Fișierul nu pare a fi un CSV de la PayPal. Descarcă din PayPal Rapport Transactiegegevens (nu raportul de sold Saldorapport) în format CSV.',
        'unreadable' => 'Fișierul nu a putut fi citit. Eroarea completă este în /dev/logs.',
    ],
];
