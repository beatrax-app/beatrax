<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Luottokorttisi',
    'h1' => 'Hae kuukausittaiset PDF-tiliotteesi',
    'lede' => 'Pudota kaikki kuukausittaiset PDF-tiliotteesi — yhdistämme ne yhdeksi esikatseluksi.',

    'format_group_aria' => 'ICS vie vain PDF-muodossa',
    'issuer_note' => 'ICS on toistaiseksi ainoa kortin myöntäjä, jonka osaamme lukea, ja vain sen hollanninkielisen tiliotteen. Jos korttisi on muulta myöntäjältä, ohita tämä vaihe.',
    'got_it_as' => 'Sain sen muodossa:',
    'badge_only_format' => 'ainoa muoto',

    'mini' => [
        'login_label' => 'Kirjaudu sisään',
        'statements_label' => 'Avaa tiliotteet',
        'months_label' => 'Valitse kuukaudet',
        'months_sub' => 'Yksi PDF kuukautta kohden',
        'download_label' => 'Lataa',
    ],

    'drop_lead' => 'Pudota ICS-PDF-tiedostosi tähän',
    'browse_files' => 'tai selaa tiedostoja',
    'queue_aria' => 'Jonossa olevat PDF-tiliotteet',

    'skip' => 'Ohita tämä vaihe',
    'continue' => 'Jatka →',

    'errors' => [
        'required' => 'Pudota Mijn ICS -palvelusta lataamasi kuukausittaiset PDF-tiliotteet.',
        'min' => 'Pudota vähintään yksi ICS-PDF-tiliote ennen jatkamista.',
        'each_required' => 'Pudota Mijn ICS -palvelusta lataamasi kuukausittainen PDF-tiliote.',
        'each_max' => 'Yksi tiedostoistasi on liian suuri. ICS-PDF-tiliotteet ovat yleensä alle 1 Mt kukin.',
        'each_extensions' => 'Yksi tiedostoistasi ei ole PDF. Mijn ICS vie vain PDF-muodossa — kokeile uusinta kuukausitiliotetta.',
        'file_unreadable' => 'Tiedostoa :filename ei voitu lukea. Koko virhe löytyy polusta /dev/logs.',
        'none_readable' => 'Emme pystyneet lukemaan yhtäkään ICS-PDF-tiedostoistasi. :detail',
        'full_error_in_logs' => 'Koko virhe löytyy polusta /dev/logs.',
    ],
];
