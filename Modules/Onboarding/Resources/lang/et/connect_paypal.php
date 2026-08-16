<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Sinu PayPali konto',
    'h1' => 'Ühenda oma PayPali konto',

    'lede_html' => 'Lohista siia oma PayPali tehingute üksikasjade eksport — Hollandi PayPali kontol on see <em lang="nl">Rapport Transactiegegevens</em>. Jäägiaruanne (<span lang="nl">Saldorapport</span>) ei sobi — vajame andmeid sündmuste kaupa.',

    'format_group_aria' => 'PayPal ekspordib ainult CSV-d',
    'got_it_as' => 'Sain selle kujul:',
    'badge_only_format' => 'ainus vorming',

    'mini' => [
        'login_label' => 'Logi sisse',
        'custom_label' => 'Kohandatud väljavõtted',
        'range_label' => 'Vali vahemik',
        'range_sub' => 'Viimased 12 kuud',
        'download_label' => 'Laadi alla CSV-na',
    ],

    'drop_lead' => 'Lohista oma tehingute üksikasjade CSV siia',
    'browse_file' => 'või otsi fail üles',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Jäta see samm vahele',
    'continue' => 'Jätka →',

    'errors' => [
        'required' => 'Lohista kõigepealt kasti oma PayPali Rapport Transactiegegevens CSV.',
        'max' => 'See fail on liiga suur. PayPali Rapport Transactiegegevens ekspordid jäävad tavaliselt tublisti alla 10 MB.',
        'extensions' => 'See fail ei tundu olevat PayPali CSV. Laadi PayPalist alla Rapport Transactiegegevens (mitte jäägiaruanne Saldorapport) CSV-na.',
        'unreadable' => 'Seda faili ei õnnestunud lugeda. Täielik viga on kaustas /dev/logs.',
    ],
];
