<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pankkisi',
    'h1' => 'Hae tiliote ja pudota se alle',
    'lede' => 'Valitse pankiltasi saamasi muoto ja pudota tiedosto. Tunnistamme CAMT.053- ja MT940-muodot automaattisesti.',

    'format_group_aria' => 'Tiliotteen muoto',
    'got_it_as' => 'Sain sen muodossa:',
    'badge_recommended' => 'suositeltu',

    'mini' => [
        'login_label' => 'Kirjaudu sisään',
        'login_sub' => 'Pankkisi verkkosivusto',
        'statements_label' => 'Avaa tiliotteet',
        'statements_sub' => 'Pankkisi valikossa',
        'range_label' => 'Valitse aikaväli',
        'range_sub' => 'Viimeiset 90 päivää',
        'download_label' => 'Lataa',
    ],

    'csv_picker_aria' => 'Mikä pankki vei CSV-tiedostosi?',
    'csv_picker_from' => 'Lähde:',

    'drop_lead_camt053' => 'Pudota CAMT.053-tiedostosi tähän',
    'drop_lead_mt940' => 'Pudota MT940-tiedostosi tähän',
    'drop_lead_csv_layout' => 'Pudota :layout-CSV-tiedostosi tähän',
    'drop_lead_pick_bank' => 'Valitse, mikä pankki vei CSV-tiedostosi — tarvitsemme sen tiedon lukeaksemme sen oikein.',
    'drop_lead_default' => 'Pudota tiliotetiedostosi tähän',
    'browse_file' => 'tai selaa tiedosto',

    'format_help_camt053' => 'CAMT.053 on XML-muotoinen tiliote — etsi se verkkopankista tiliotteiden tai latausten kohdalta.',
    'format_help_mt940' => 'MT940 on tekstimuotoinen tiliote, tarjolla .sta- tai .940-tiedostona XML- ja CSV-latausten vieressä.',
    'format_help_csv' => 'CSV on taulukkolaskennan vienti. Jokainen pankki järjestää sarakkeet omalla tavallaan, joten valitse sopiva asettelu. Jos omaasi ei ole listalla, pyydä pankiltasi CAMT.053- tai MT940-tiedostoa.',

    'account_name_default' => 'Pankkitili',
    'account_name_layout' => ':layout-tili',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Ohita tämä vaihe',
    'continue' => 'Jatka →',

    'errors' => [
        'file_required' => 'Pudota tiliotetiedostosi ensin laatikkoon.',
        'file_max' => 'Tiedosto on liian suuri. Pudota tiliote, joka on alle 10 Mt.',
        'file_extensions' => 'Tämä tiedosto ei näytä tiliotteelta. Pudota CAMT.053 XML-, CSV- tai MT940-tiedosto.',
        'pick_bank' => 'Valitse ennen jatkamista, mikä pankki vei CSV-tiedostosi.',
        'unreadable' => 'Tätä tiedostoa ei voitu lukea. Koko virhe löytyy polusta /dev/logs.',
    ],
];
