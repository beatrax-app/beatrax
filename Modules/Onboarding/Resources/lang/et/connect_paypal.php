<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Sinu PayPali konto',
    'h1' => 'Ühenda oma PayPali konto',

    'lede_html' => 'Lohista siia oma PayPali liikumiste eksport — üks rida tehingu kohta, mitte jäägi kokkuvõte. PayPal nimetab oma aruandeid sinu konto keeles ja praegu loeme hollandikeelset paari: <em lang="nl">Rapport Transactiegegevens</em>, mitte <span lang="nl">Saldorapport</span>. Kui sinu oma tuleb mõnes teises keeles, lülita PayPal enne allalaadimist hollandi keelele.',

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

    'drop_lead' => 'Lohista oma liikumiste eksport siia',
    'browse_file' => 'või otsi fail üles',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Jäta see samm vahele',
    'continue' => 'Jätka →',

    'errors' => [
        'required' => 'Lohista kõigepealt kasti oma PayPali liikumiste eksport.',
        'max' => 'See fail on liiga suur. PayPali liikumiste eksport jääb tavaliselt tublisti alla 10 MB.',
        'extensions' => 'See fail ei tundu olevat PayPali CSV. Laadi alla liikumiste eksport — üks rida tehingu kohta, mitte jäägi kokkuvõte — CSV-na.',
        'unreadable' => 'Seda faili ei õnnestunud lugeda. Täielik viga on kaustas /dev/logs.',
    ],
];
