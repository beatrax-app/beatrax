<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal-tilisi',
    'h1' => 'Yhdistä PayPal-tilisi',

    'lede_html' => 'Pudota PayPalin tapahtumavienti — yksi rivi per tapahtuma, ei saldon yhteenvetoa. PayPal nimeää raporttinsa tilisi kielellä, ja toistaiseksi luemme hollanninkielisen parin: <em lang="nl">Rapport Transactiegegevens</em>, ei <span lang="nl">Saldorapport</span>. Jos omasi tulee muulla kielellä, vaihda PayPal hollanniksi ennen lataamista.',

    'format_group_aria' => 'PayPal vie vain CSV-muodossa',
    'got_it_as' => 'Sain sen muodossa:',
    'badge_only_format' => 'ainoa muoto',

    'mini' => [
        'login_label' => 'Kirjaudu sisään',
        'custom_label' => 'Mukautetut raportit',
        'range_label' => 'Valitse aikaväli',
        'range_sub' => 'Viimeiset 12 kuukautta',
        'download_label' => 'Lataa CSV-muodossa',
    ],

    'drop_lead' => 'Pudota tapahtumavientisi tähän',
    'browse_file' => 'tai selaa tiedosto',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Ohita tämä vaihe',
    'continue' => 'Jatka →',

    'errors' => [
        'required' => 'Pudota PayPalin tapahtumavienti ensin laatikkoon.',
        'max' => 'Tiedosto on liian suuri. PayPalin tapahtumavienti jää yleensä selvästi alle 10 Mt.',
        'extensions' => 'Tämä tiedosto ei näytä PayPalin CSV-tiedostolta. Lataa tapahtumavienti — yksi rivi per tapahtuma, ei saldon yhteenvetoa — CSV-muodossa.',
        'unreadable' => 'Tätä tiedostoa ei voitu lukea. Koko virhe löytyy polusta /dev/logs.',
    ],
];
