<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoje banka',
    'h1' => 'Stáhni si výpis a přetáhni ho sem',
    'lede' => 'Vyber formát, ve kterém ti banka výpis dala, a přetáhni soubor. CAMT.053 a MT940 poznáme sami.',

    'format_group_aria' => 'Formát bankovního výpisu',
    'got_it_as' => 'Mám ho jako:',
    'badge_recommended' => 'doporučeno',

    'mini' => [
        'login_label' => 'Přihlaš se',
        'login_sub' => 'Web tvé banky',
        'statements_label' => 'Otevři výpisy',
        'statements_sub' => 'V menu banky',
        'range_label' => 'Vyber rozsah',
        'range_sub' => 'Posledních 90 dní',
        'download_label' => 'Stáhni',
    ],

    'csv_picker_aria' => 'Ze které banky je tvůj soubor CSV?',
    'csv_picker_from' => 'Z banky:',

    'drop_lead_camt053' => 'Přetáhni sem soubor CAMT.053',
    'drop_lead_mt940' => 'Přetáhni sem soubor MT940',
    'drop_lead_asn' => 'Přetáhni sem CSV z ASN',
    'drop_lead_ing' => 'Přetáhni sem CSV z ING',
    'drop_lead_pick_bank' => 'Vyber banku, ze které je tvůj soubor CSV — bez toho ho nepřečteme správně.',
    'drop_lead_default' => 'Přetáhni sem soubor s výpisem',
    'browse_file' => 'nebo vyber soubor z disku',

    'banks_mt940' => 'Podporováno: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Podporováno: ASN, ING — další formáty přibudou, jak budou uživatelé posílat vzorky.',
    'banks_default' => 'Podporováno: ASN, ING',

    'file_ready' => '· ✓ hotovo',

    'skip' => 'Přeskočit tento krok',
    'continue' => 'Pokračovat →',

    'errors' => [
        'file_required' => 'Nejdřív přetáhni soubor s výpisem do pole.',
        'file_max' => 'Tento soubor je moc velký. Přetáhni výpis menší než 10 MB.',
        'file_extensions' => 'Tento soubor nevypadá jako bankovní výpis. Přetáhni soubor CAMT.053 XML, CSV nebo MT940.',
        'pick_bank' => 'Než budeš pokračovat, vyber banku, ze které je tvůj soubor CSV.',
        'unreadable' => 'Tento soubor se nepodařilo přečíst. Celá chyba je v /dev/logs.',
    ],
];
