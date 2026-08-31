<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Je bank',
    'h1' => 'Pak een afschrift en zet het hieronder neer',
    'lede' => 'Kies het formaat dat je bank je gaf en zet het bestand neer. We herkennen CAMT.053 en MT940 automatisch.',

    'format_group_aria' => 'Formaat bankafschrift',
    'got_it_as' => 'Gekregen als:',
    'badge_recommended' => 'aanbevolen',

    'mini' => [
        'login_label' => 'Inloggen',
        'login_sub' => 'De website van je bank',
        'statements_label' => 'Afschriften openen',
        'statements_sub' => 'In het menu van je bank',
        'range_label' => 'Kies een periode',
        'range_sub' => 'Laatste 90 dagen',
        'download_label' => 'Downloaden',
    ],

    'csv_picker_aria' => 'Welke bank heeft je CSV geëxporteerd?',
    'csv_picker_from' => 'Van:',

    'drop_lead_camt053' => 'Zet je CAMT.053-bestand hier neer',
    'drop_lead_mt940' => 'Zet je MT940-bestand hier neer',
    'drop_lead_csv_layout' => 'Zet je :layout-CSV hier neer',
    'drop_lead_pick_bank' => 'Kies welke bank je CSV heeft geëxporteerd — dat moeten we weten om hem goed te lezen.',
    'drop_lead_default' => 'Zet je afschriftbestand hier neer',
    'browse_file' => 'of blader naar een bestand',

    'format_help_camt053' => 'CAMT.053 is een afschrift in XML — kijk in je online bankieren bij afschriften of downloads.',
    'format_help_mt940' => 'MT940 is een afschrift in platte tekst, aangeboden als .sta of .940 naast de XML- en CSV-downloads.',
    'format_help_csv' => 'CSV is de export voor spreadsheets. Elke bank zet de kolommen anders neer, dus kies de indeling die past. Staat de jouwe er niet bij, vraag je bank dan om CAMT.053 of MT940.',

    'account_name_default' => 'Bankrekening',
    'account_name_layout' => ':layout-rekening',

    'file_ready' => '· ✓ klaar',

    'skip' => 'Sla deze stap over',
    'continue' => 'Doorgaan →',

    'errors' => [
        'file_required' => 'Zet eerst je afschriftbestand in het vak.',
        'file_max' => 'Dat bestand is te groot. Zet een afschrift onder 10 MB neer.',
        'file_extensions' => 'Dat bestand lijkt niet op een bankafschrift. Zet een CAMT.053-XML, CSV of MT940-bestand neer.',
        'pick_bank' => 'Kies welke bank je CSV heeft geëxporteerd voordat je verdergaat.',
        'unreadable' => 'Kon dit bestand niet lezen. De volledige fout staat in /dev/logs.',
    ],
];
