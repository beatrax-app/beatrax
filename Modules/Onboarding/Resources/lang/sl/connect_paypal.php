<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj račun PayPal',
    'h1' => 'Poveži svoj račun PayPal',

    'lede_html' => 'Spusti izvoz podrobnosti o transakcijah PayPal — na nizozemskem računu PayPal naveden kot <em lang="nl">Rapport Transactiegegevens</em>. Poročilo o stanju (<span lang="nl">Saldorapport</span>) ne bo delovalo — potrebujemo podatke po dogodkih.',

    'format_group_aria' => 'PayPal izvaža samo v CSV',
    'got_it_as' => 'Preneseno kot:',
    'badge_only_format' => 'edina oblika',

    'mini' => [
        'login_label' => 'Prijavi se',
        'custom_label' => 'Prilagojeni izpiski',
        'range_label' => 'Izberi obdobje',
        'range_sub' => 'Zadnjih 12 mesecev',
        'download_label' => 'Prenesi kot CSV',
    ],

    'drop_lead' => 'Sem spusti CSV s podrobnostmi o transakcijah',
    'browse_file' => 'ali poišči datoteko',

    'file_ready' => '· ✓ pripravljeno',

    'skip' => 'Preskoči ta korak',
    'continue' => 'Nadaljuj →',

    'errors' => [
        'required' => 'Najprej spusti svoj CSV PayPal Rapport Transactiegegevens v okvir.',
        'max' => 'Ta datoteka je prevelika. Izvozi PayPal Rapport Transactiegegevens so običajno precej manjši od 10 MB.',
        'extensions' => 'Ta datoteka ni videti kot CSV PayPal. S PayPala prenesi Rapport Transactiegegevens (ne poročila o stanju Saldorapport) kot CSV.',
        'unreadable' => 'Te datoteke ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
    ],
];
