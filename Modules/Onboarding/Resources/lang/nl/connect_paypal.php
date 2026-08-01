<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Je PayPal-account',
    'h1' => 'Koppel je PayPal-account',
    'lede_html' => 'Zet je PayPal-export met transactiegegevens neer — in een Nederlands PayPal-account heet dit <em lang="nl">Rapport Transactiegegevens</em>. Het saldorapport (<span lang="nl">Saldorapport</span>) werkt niet — we hebben gegevens per transactie nodig.',

    'format_group_aria' => 'PayPal exporteert alleen als CSV',
    'got_it_as' => 'Gekregen als:',
    'badge_only_format' => 'enige formaat',

    'mini' => [
        'login_label' => 'Inloggen',
        'custom_label' => 'Aangepaste afschriften',
        'range_label' => 'Kies een periode',
        'range_sub' => 'Laatste 12 maanden',
        'download_label' => 'Downloaden als CSV',
    ],

    'drop_lead' => 'Zet je CSV met transactiegegevens hier neer',
    'browse_file' => 'of blader naar een bestand',

    'file_ready' => '· ✓ klaar',

    'skip' => 'Sla deze stap over',
    'continue' => 'Doorgaan →',

    'errors' => [
        'required' => 'Zet eerst je PayPal Rapport Transactiegegevens-CSV in het vak.',
        'max' => 'Dat bestand is te groot. PayPal Rapport Transactiegegevens-exports zijn normaal ruim onder de 10 MB.',
        'extensions' => 'Dat bestand lijkt niet op een PayPal-CSV. Download Rapport Transactiegegevens (niet het Saldorapport) als CSV van PayPal.',
        'unreadable' => 'Kon dit bestand niet lezen. De volledige fout staat in /dev/logs.',
    ],
];
