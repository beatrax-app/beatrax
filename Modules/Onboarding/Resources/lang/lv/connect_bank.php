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
    'drop_lead_csv_layout' => 'Ievelciet šeit savu :layout CSV failu',
    'drop_lead_pick_bank' => 'Izvēlieties, kura banka eksportēja jūsu CSV — bez tā to nevar pareizi nolasīt.',
    'drop_lead_default' => 'Ievelciet šeit konta izraksta failu',
    'browse_file' => 'vai izvēlieties failu',

    'format_help_camt053' => 'CAMT.053 ir XML formāta konta izraksts — meklē to internetbankā pie izrakstiem vai lejupielādēm.',
    'format_help_mt940' => 'MT940 ir vienkārša teksta izraksts, ko piedāvā kā .sta vai .940 blakus XML un CSV lejupielādēm.',
    'format_help_csv' => 'CSV ir izklājlapu eksports. Katra banka kolonnas sakārto savādāk, tāpēc izvēlies atbilstošo izkārtojumu. Ja jūsu izkārtojuma sarakstā nav, palūdziet bankai CAMT.053 vai MT940.',

    'account_name_default' => 'Bankas konts',
    'account_name_layout' => ':layout konts',

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
