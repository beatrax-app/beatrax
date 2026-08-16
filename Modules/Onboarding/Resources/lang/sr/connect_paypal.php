<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj PayPal nalog',
    'h1' => 'Poveži svoj PayPal nalog',

    'lede_html' => 'Prevuci izvoz detalja PayPal transakcija — na holandskom PayPal nalogu naveden kao <em lang="nl">Rapport Transactiegegevens</em>. Izveštaj o stanju (<span lang="nl">Saldorapport</span>) neće raditi — trebaju nam podaci po događaju.',

    'format_group_aria' => 'PayPal izvozi isključivo u CSV',
    'got_it_as' => 'Preuzeto kao:',
    'badge_only_format' => 'jedini format',

    'mini' => [
        'login_label' => 'Prijavi se',
        'custom_label' => 'Prilagođeni izvodi',
        'range_label' => 'Izaberi period',
        'range_sub' => 'Poslednjih 12 meseci',
        'download_label' => 'Preuzmi kao CSV',
    ],

    'drop_lead' => 'Ovde prevuci CSV sa detaljima transakcija',
    'browse_file' => 'ili potraži datoteku',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'required' => 'Prvo prevuci svoj PayPal Rapport Transactiegegevens CSV u okvir.',
        'max' => 'Ta datoteka je prevelika. Izvozi PayPal Rapport Transactiegegevens su obično znatno manji od 10 MB.',
        'extensions' => 'Ta datoteka ne izgleda kao PayPal CSV. Sa PayPala preuzmi Rapport Transactiegegevens (ne izveštaj o stanju Saldorapport) kao CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cela greška je u /dev/logs.',
    ],
];
