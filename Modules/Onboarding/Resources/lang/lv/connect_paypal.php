<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Jūsu PayPal konts',
    'h1' => 'Pievienojiet savu PayPal kontu',

    'lede_html' => 'Ievelciet savu PayPal darījumu detalizēto eksportu — nīderlandiešu PayPal kontā tas saucas <em lang="nl">Rapport Transactiegegevens</em>. Atlikuma pārskats (<span lang="nl">Saldorapport</span>) neder — mums vajadzīgi dati par katru notikumu.',

    'format_group_aria' => 'PayPal eksportē tikai CSV formātā',
    'got_it_as' => 'Saņēmu kā:',
    'badge_only_format' => 'vienīgais formāts',

    'mini' => [
        'login_label' => 'Piesakieties',
        'custom_label' => 'Pielāgoti pārskati',
        'range_label' => 'Izvēlieties periodu',
        'range_sub' => 'Pēdējie 12 mēneši',
        'download_label' => 'Lejupielādējiet kā CSV',
    ],

    'drop_lead' => 'Ievelciet šeit savu darījumu detalizēto CSV failu',
    'browse_file' => 'vai izvēlieties failu',

    'file_ready' => '· ✓ gatavs',

    'skip' => 'Izlaist šo soli',
    'continue' => 'Turpināt →',

    'errors' => [
        'required' => 'Vispirms ievelciet lodziņā savu PayPal Rapport Transactiegegevens CSV failu.',
        'max' => 'Šis fails ir pārāk liels. PayPal Rapport Transactiegegevens eksporti parasti ir krietni mazāki par 10 MB.',
        'extensions' => 'Šis fails neizskatās pēc PayPal CSV faila. Lejupielādējiet no PayPal Rapport Transactiegegevens (nevis Saldorapport atlikuma pārskatu) CSV formātā.',
        'unreadable' => 'Šo failu neizdevās nolasīt. Pilna kļūda ir pieejama /dev/logs.',
    ],
];
