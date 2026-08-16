<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Jūsu banka',
    'h1' => 'Paņemiet konta izrakstu un ievelciet to zemāk',
    'lede' => 'Izvēlieties formātu, kādā banka izsniedza failu, un ievelciet to šeit. CAMT.053 un MT940 nosakām automātiski.',

    'format_group_aria' => 'Konta izraksta formāts',
    'got_it_as' => 'Saņēmu kā:',
    'badge_recommended' => 'ieteicams',

    'mini' => [
        'login_label' => 'Piesakieties',
        'login_sub' => 'Bankas tīmekļa vietnē',
        'statements_label' => 'Atveriet konta izrakstus',
        'statements_sub' => 'Bankas izvēlnē',
        'range_label' => 'Izvēlieties periodu',
        'range_sub' => 'Pēdējās 90 dienas',
        'download_label' => 'Lejupielādējiet',
    ],

    'csv_picker_aria' => 'Kura banka eksportēja jūsu CSV?',
    'csv_picker_from' => 'No:',

    'drop_lead_camt053' => 'Ievelciet šeit savu CAMT.053 failu',
    'drop_lead_mt940' => 'Ievelciet šeit savu MT940 failu',
    'drop_lead_asn' => 'Ievelciet šeit savu ASN CSV failu',
    'drop_lead_ing' => 'Ievelciet šeit savu ING CSV failu',
    'drop_lead_pick_bank' => 'Izvēlieties, kura banka eksportēja jūsu CSV — bez tā to nevar pareizi nolasīt.',
    'drop_lead_default' => 'Ievelciet šeit konta izraksta failu',
    'browse_file' => 'vai izvēlieties failu',

    'banks_mt940' => 'Atbalstītas: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Atbalstītas: ASN, ING — jauni formāti tiks pievienoti, kad lietotāji atsūtīs paraugus.',
    'banks_default' => 'Atbalstītas: ASN, ING',

    'file_ready' => '· ✓ gatavs',

    'skip' => 'Izlaist šo soli',
    'continue' => 'Turpināt →',

    'errors' => [
        'file_required' => 'Vispirms ievelciet konta izraksta failu lodziņā.',
        'file_max' => 'Šis fails ir pārāk liels. Ievelciet konta izrakstu, kas mazāks par 10 MB.',
        'file_extensions' => 'Šis fails neizskatās pēc bankas konta izraksta. Ievelciet CAMT.053 XML, CSV vai MT940 failu.',
        'pick_bank' => 'Pirms turpināt izvēlieties, kura banka eksportēja jūsu CSV.',
        'unreadable' => 'Šo failu neizdevās nolasīt. Pilna kļūda ir pieejama /dev/logs.',
    ],
];
