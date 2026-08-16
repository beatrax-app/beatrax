<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Sinu pank',
    'h1' => 'Võta väljavõte ja lohista see allolevasse kasti',
    'lede' => 'Vali vorming, mille pank sulle andis, ja lohista fail. CAMT.053 ja MT940 tuvastame ise.',

    'format_group_aria' => 'Kontoväljavõtte vorming',
    'got_it_as' => 'Sain selle kujul:',
    'badge_recommended' => 'soovitatud',

    'mini' => [
        'login_label' => 'Logi sisse',
        'login_sub' => 'Sinu panga veebisaidil',
        'statements_label' => 'Ava väljavõtted',
        'statements_sub' => 'Sinu panga menüüs',
        'range_label' => 'Vali vahemik',
        'range_sub' => 'Viimased 90 päeva',
        'download_label' => 'Laadi alla',
    ],

    'csv_picker_aria' => 'Milline pank sinu CSV eksportis?',
    'csv_picker_from' => 'Kust:',

    'drop_lead_camt053' => 'Lohista oma CAMT.053 fail siia',
    'drop_lead_mt940' => 'Lohista oma MT940 fail siia',
    'drop_lead_asn' => 'Lohista oma ASN CSV siia',
    'drop_lead_ing' => 'Lohista oma ING CSV siia',
    'drop_lead_pick_bank' => 'Vali, milline pank sinu CSV eksportis — ilma selleta ei oska me seda õigesti lugeda.',
    'drop_lead_default' => 'Lohista oma väljavõtte fail siia',
    'browse_file' => 'või otsi fail üles',

    'banks_mt940' => 'Toetatud: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Toetatud: ASN, ING — vorminguid lisandub, kui kasutajad näidiseid jagavad.',
    'banks_default' => 'Toetatud: ASN, ING',

    'file_ready' => '· ✓ valmis',

    'skip' => 'Jäta see samm vahele',
    'continue' => 'Jätka →',

    'errors' => [
        'file_required' => 'Lohista kõigepealt oma väljavõtte fail kasti.',
        'file_max' => 'See fail on liiga suur. Lohista väljavõte, mis on alla 10 MB.',
        'file_extensions' => 'See fail ei tundu olevat kontoväljavõte. Lohista CAMT.053 XML, CSV või MT940 fail.',
        'pick_bank' => 'Vali enne jätkamist, milline pank sinu CSV eksportis.',
        'unreadable' => 'Seda faili ei õnnestunud lugeda. Täielik viga on kaustas /dev/logs.',
    ],
];
