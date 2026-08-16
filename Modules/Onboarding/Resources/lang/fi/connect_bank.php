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
    'drop_lead_asn' => 'Pudota ASN-CSV-tiedostosi tähän',
    'drop_lead_ing' => 'Pudota ING-CSV-tiedostosi tähän',
    'drop_lead_pick_bank' => 'Valitse, mikä pankki vei CSV-tiedostosi — tarvitsemme sen tiedon lukeaksemme sen oikein.',
    'drop_lead_default' => 'Pudota tiliotetiedostosi tähän',
    'browse_file' => 'tai selaa tiedosto',

    'banks_mt940' => 'Tuetut: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Tuetut: ASN, ING — lisää muotoja tulossa sitä mukaa kun käyttäjät toimittavat näytteitä.',
    'banks_default' => 'Tuetut: ASN, ING',

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
