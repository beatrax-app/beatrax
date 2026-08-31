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
    'drop_lead_csv_layout' => 'Lohista oma :layout CSV siia',
    'drop_lead_pick_bank' => 'Vali, milline pank sinu CSV eksportis — ilma selleta ei oska me seda õigesti lugeda.',
    'drop_lead_default' => 'Lohista oma väljavõtte fail siia',
    'browse_file' => 'või otsi fail üles',

    'format_help_camt053' => 'CAMT.053 on XML-vormingus väljavõte — otsi seda internetipangas väljavõtete või allalaadimiste alt.',
    'format_help_mt940' => 'MT940 on lihttekstis väljavõte, pakutakse laienditega .sta või .940 XML- ja CSV-failide kõrval.',
    'format_help_csv' => 'CSV on tabelarvutuse eksport. Iga pank paigutab veerud isemoodi, seega vali sobiv paigutus. Kui sinu oma loendis pole, küsi pangalt hoopis CAMT.053 või MT940 faili.',

    'account_name_default' => 'Pangakonto',
    'account_name_layout' => ':layout konto',

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
