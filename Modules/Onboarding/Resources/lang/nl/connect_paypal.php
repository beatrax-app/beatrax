<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Je PayPal-account',
    'h1' => 'Koppel je PayPal-account',
    'lede_html' => 'Zet je PayPal-transactie-export neer — één regel per transactie, niet het saldo-overzicht. PayPal noemt zijn rapporten in de taal van je account, en voorlopig lezen we het Nederlandse paar: <em lang="nl">Rapport Transactiegegevens</em>, niet <span lang="nl">Saldorapport</span>. Komt die van jou in een andere taal, zet PayPal dan op Nederlands voordat je downloadt.',

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

    'drop_lead' => 'Zet je transactie-export hier neer',
    'browse_file' => 'of blader naar een bestand',

    'file_ready' => '· ✓ klaar',

    'skip' => 'Sla deze stap over',
    'continue' => 'Doorgaan →',

    'errors' => [
        'required' => 'Zet eerst je PayPal-transactie-export in het vak.',
        'max' => 'Dat bestand is te groot. Een PayPal-transactie-export is normaal ruim onder de 10 MB.',
        'extensions' => 'Dat bestand lijkt niet op een PayPal-CSV. Download de transactie-export — één regel per transactie, niet het saldo-overzicht — als CSV.',
        'unreadable' => 'Kon dit bestand niet lezen. De volledige fout staat in /dev/logs.',
    ],
];
