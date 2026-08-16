<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja banka',
    'h1' => 'Prenesi izpisek in ga spusti spodaj',
    'lede' => 'Izberi obliko, ki ti jo je dala banka, in spusti datoteko. CAMT.053 in MT940 zaznamo samodejno.',

    'format_group_aria' => 'Oblika bančnega izpiska',
    'got_it_as' => 'Preneseno kot:',
    'badge_recommended' => 'priporočeno',

    'mini' => [
        'login_label' => 'Prijavi se',
        'login_sub' => 'Spletna stran tvoje banke',
        'statements_label' => 'Odpri izpiske',
        'statements_sub' => 'V meniju tvoje banke',
        'range_label' => 'Izberi obdobje',
        'range_sub' => 'Zadnjih 90 dni',
        'download_label' => 'Prenesi',
    ],

    'csv_picker_aria' => 'Katera banka je izvozila tvoj CSV?',
    'csv_picker_from' => 'Iz:',

    'drop_lead_camt053' => 'Sem spusti svojo datoteko CAMT.053',
    'drop_lead_mt940' => 'Sem spusti svojo datoteko MT940',
    'drop_lead_asn' => 'Sem spusti svoj CSV ASN',
    'drop_lead_ing' => 'Sem spusti svoj CSV ING',
    'drop_lead_pick_bank' => 'Izberi, katera banka je izvozila tvoj CSV — to moramo vedeti, da ga pravilno preberemo.',
    'drop_lead_default' => 'Sem spusti datoteko izpiska',
    'browse_file' => 'ali poišči datoteko',

    'banks_mt940' => 'Podprto: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Podprto: ASN, ING — več oblik prihaja, ko uporabniki prispevajo vzorce.',
    'banks_default' => 'Podprto: ASN, ING',

    'file_ready' => '· ✓ pripravljeno',

    'skip' => 'Preskoči ta korak',
    'continue' => 'Nadaljuj →',

    'errors' => [
        'file_required' => 'Najprej spusti datoteko izpiska v okvir.',
        'file_max' => 'Ta datoteka je prevelika. Spusti izpisek, manjši od 10 MB.',
        'file_extensions' => 'Ta datoteka ni videti kot bančni izpisek. Spusti datoteko CAMT.053 XML, CSV ali MT940.',
        'pick_bank' => 'Pred nadaljevanjem izberi, katera banka je izvozila tvoj CSV.',
        'unreadable' => 'Te datoteke ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
    ],
];
