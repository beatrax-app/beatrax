<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ditt PayPal-konto',
    'h1' => 'Koppla ditt PayPal-konto',

    'lede_html' => 'Släpp din PayPal-export med transaktionsdetaljer — den heter <em lang="nl">Rapport Transactiegegevens</em> i ett nederländskt PayPal-konto. Saldorapporten (<span lang="nl">Saldorapport</span>) fungerar inte — vi behöver data per händelse.',

    'format_group_aria' => 'PayPal exporterar endast som CSV',
    'got_it_as' => 'Du fick det som:',
    'badge_only_format' => 'enda formatet',

    'mini' => [
        'login_label' => 'Logga in',
        'custom_label' => 'Anpassade kontoutdrag',
        'range_label' => 'Välj en period',
        'range_sub' => 'Senaste 12 månaderna',
        'download_label' => 'Ladda ner som CSV',
    ],

    'drop_lead' => 'Släpp din CSV med transaktionsdetaljer här',
    'browse_file' => 'eller bläddra efter en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hoppa över det här steget',
    'continue' => 'Fortsätt →',

    'errors' => [
        'required' => 'Släpp först din PayPal-CSV Rapport Transactiegegevens i rutan.',
        'max' => 'Filen är för stor. Exporter av Rapport Transactiegegevens från PayPal ligger normalt en bra bit under 10 MB.',
        'extensions' => 'Filen ser inte ut som en PayPal-CSV. Ladda ner Rapport Transactiegegevens (inte saldorapporten Saldorapport) som CSV från PayPal.',
        'unreadable' => 'Kunde inte läsa filen. Hela felet finns i /dev/logs.',
    ],
];
