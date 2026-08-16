<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Banca ta',
    'h1' => 'Ia un extras de cont, apoi trage-l mai jos',
    'lede' => 'Alege formatul primit de la bancă, apoi trage fișierul aici. Detectăm automat CAMT.053 și MT940.',

    'format_group_aria' => 'Formatul extrasului de cont',
    'got_it_as' => 'L-ai primit ca:',
    'badge_recommended' => 'recomandat',

    'mini' => [
        'login_label' => 'Autentifică-te',
        'login_sub' => 'Pe site-ul băncii tale',
        'statements_label' => 'Deschide extrasele',
        'statements_sub' => 'În meniul băncii tale',
        'range_label' => 'Alege o perioadă',
        'range_sub' => 'Ultimele 90 de zile',
        'download_label' => 'Descarcă',
    ],

    'csv_picker_aria' => 'Ce bancă a exportat CSV-ul tău?',
    'csv_picker_from' => 'De la:',

    'drop_lead_camt053' => 'Trage aici fișierul CAMT.053',
    'drop_lead_mt940' => 'Trage aici fișierul MT940',
    'drop_lead_asn' => 'Trage aici CSV-ul ASN',
    'drop_lead_ing' => 'Trage aici CSV-ul ING',
    'drop_lead_pick_bank' => 'Alege ce bancă a exportat CSV-ul — trebuie să știm ca să îl citim corect.',
    'drop_lead_default' => 'Trage aici fișierul cu extrasul de cont',
    'browse_file' => 'sau caută un fișier',

    'banks_mt940' => 'Compatibile: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Compatibile: ASN, ING — urmează mai multe formate, pe măsură ce utilizatorii trimit mostre.',
    'banks_default' => 'Compatibile: ASN, ING',

    'file_ready' => '· ✓ gata',

    'skip' => 'Omite acest pas',
    'continue' => 'Continuă →',

    'errors' => [
        'file_required' => 'Trage mai întâi fișierul cu extrasul de cont în casetă.',
        'file_max' => 'Fișierul este prea mare. Trage un extras sub 10 MB.',
        'file_extensions' => 'Fișierul nu pare a fi un extras de cont. Trage un fișier CAMT.053 XML, CSV sau MT940.',
        'pick_bank' => 'Alege ce bancă a exportat CSV-ul înainte să continui.',
        'unreadable' => 'Fișierul nu a putut fi citit. Eroarea completă este în /dev/logs.',
    ],
];
