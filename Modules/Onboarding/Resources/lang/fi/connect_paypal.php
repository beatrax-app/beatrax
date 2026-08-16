<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal-tilisi',
    'h1' => 'Yhdistä PayPal-tilisi',

    'lede_html' => 'Pudota PayPalin tapahtumatietojen vienti — hollantilaisella PayPal-tilillä se on nimeltään <em lang="nl">Rapport Transactiegegevens</em>. Saldoraportti (<span lang="nl">Saldorapport</span>) ei kelpaa — tarvitsemme tapahtumakohtaiset tiedot.',

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

    'drop_lead' => 'Pudota tapahtumatietojen CSV-tiedostosi tähän',
    'browse_file' => 'tai selaa tiedosto',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Ohita tämä vaihe',
    'continue' => 'Jatka →',

    'errors' => [
        'required' => 'Pudota PayPalin Rapport Transactiegegevens -CSV ensin laatikkoon.',
        'max' => 'Tiedosto on liian suuri. PayPalin Rapport Transactiegegevens -viennit jäävät yleensä selvästi alle 10 Mt.',
        'extensions' => 'Tämä tiedosto ei näytä PayPalin CSV-tiedostolta. Lataa PayPalista Rapport Transactiegegevens (ei Saldorapport-saldoraporttia) CSV-muodossa.',
        'unreadable' => 'Tätä tiedostoa ei voitu lukea. Koko virhe löytyy polusta /dev/logs.',
    ],
];
