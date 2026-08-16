<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja banka',
    'h1' => 'Preuzmi izvod pa ga prevuci ispod',
    'lede' => 'Izaberi format koji ti je banka dala pa prevuci datoteku. CAMT.053 i MT940 prepoznajemo automatski.',

    'format_group_aria' => 'Format bankovnog izvoda',
    'got_it_as' => 'Preuzeto kao:',
    'badge_recommended' => 'preporučeno',

    'mini' => [
        'login_label' => 'Prijavi se',
        'login_sub' => 'Veb sajt tvoje banke',
        'statements_label' => 'Otvori izvode',
        'statements_sub' => 'U meniju tvoje banke',
        'range_label' => 'Izaberi period',
        'range_sub' => 'Poslednjih 90 dana',
        'download_label' => 'Preuzmi',
    ],

    'csv_picker_aria' => 'Koja banka je izvezla tvoj CSV?',
    'csv_picker_from' => 'Iz:',

    'drop_lead_camt053' => 'Ovde prevuci svoju CAMT.053 datoteku',
    'drop_lead_mt940' => 'Ovde prevuci svoju MT940 datoteku',
    'drop_lead_asn' => 'Ovde prevuci svoj ASN CSV',
    'drop_lead_ing' => 'Ovde prevuci svoj ING CSV',
    'drop_lead_pick_bank' => 'Izaberi koja banka je izvezla tvoj CSV — moramo to da znamo da bismo ga ispravno pročitali.',
    'drop_lead_default' => 'Ovde prevuci datoteku izvoda',
    'browse_file' => 'ili potraži datoteku',

    'banks_mt940' => 'Podržano: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Podržano: ASN, ING — novi formati stižu kako korisnici šalju uzorke.',
    'banks_default' => 'Podržano: ASN, ING',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'file_required' => 'Prvo prevuci datoteku izvoda u okvir.',
        'file_max' => 'Ta datoteka je prevelika. Prevuci izvod manji od 10 MB.',
        'file_extensions' => 'Ta datoteka ne izgleda kao bankovni izvod. Prevuci CAMT.053 XML, CSV ili MT940 datoteku.',
        'pick_bank' => 'Pre nastavka izaberi koja banka je izvezla tvoj CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cela greška je u /dev/logs.',
    ],
];
