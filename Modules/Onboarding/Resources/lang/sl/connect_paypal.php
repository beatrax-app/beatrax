<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj račun PayPal',
    'h1' => 'Poveži svoj račun PayPal',

    'lede_html' => 'Spusti izvoz prometa iz PayPala — ena vrstica na transakcijo, ne povzetek stanja. PayPal svoja poročila poimenuje v jeziku tvojega računa, zaenkrat pa beremo nizozemski par: <em lang="nl">Rapport Transactiegegevens</em>, ne <span lang="nl">Saldorapport</span>. Če tvoj pride v drugem jeziku, pred prenosom preklopi PayPal na nizozemščino.',

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

    'drop_lead' => 'Spusti sem svoj izvoz prometa',
    'browse_file' => 'ali poišči datoteko',

    'file_ready' => '· ✓ pripravljeno',

    'skip' => 'Preskoči ta korak',
    'continue' => 'Nadaljuj →',

    'errors' => [
        'required' => 'Najprej spusti v okvir izvoz prometa iz PayPala.',
        'max' => 'Ta datoteka je prevelika. Izvoz prometa iz PayPala je običajno precej manjši od 10 MB.',
        'extensions' => 'Ta datoteka ni videti kot CSV iz PayPala. Prenesi izvoz prometa — ena vrstica na transakcijo, ne povzetek stanja — kot CSV.',
        'unreadable' => 'Te datoteke ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
    ],
];
