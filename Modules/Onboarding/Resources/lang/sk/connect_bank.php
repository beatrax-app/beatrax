<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja banka',
    'h1' => 'Stiahni si výpis a presuň ho sem',
    'lede' => 'Vyber formát, v ktorom ti ho banka dala, a presuň súbor. CAMT.053 a MT940 rozpoznáme automaticky.',

    'format_group_aria' => 'Formát bankového výpisu',
    'got_it_as' => 'Mám ho ako:',
    'badge_recommended' => 'odporúčané',

    'mini' => [
        'login_label' => 'Prihlás sa',
        'login_sub' => 'Web tvojej banky',
        'statements_label' => 'Otvor výpisy',
        'statements_sub' => 'V menu banky',
        'range_label' => 'Vyber obdobie',
        'range_sub' => 'Posledných 90 dní',
        'download_label' => 'Stiahni',
    ],

    'csv_picker_aria' => 'Ktorá banka vyexportovala tvoj CSV súbor?',
    'csv_picker_from' => 'Z banky:',

    'drop_lead_camt053' => 'Sem presuň súbor CAMT.053',
    'drop_lead_mt940' => 'Sem presuň súbor MT940',
    'drop_lead_asn' => 'Sem presuň CSV z ASN',
    'drop_lead_ing' => 'Sem presuň CSV z ING',
    'drop_lead_pick_bank' => 'Vyber banku, ktorá vyexportovala tvoj CSV — bez toho ho nedokážeme správne prečítať.',
    'drop_lead_default' => 'Sem presuň súbor s výpisom',
    'browse_file' => 'alebo vyber súbor z disku',

    'banks_mt940' => 'Podporované: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Podporované: ASN, ING — ďalšie formáty pribudnú, ako budú používatelia posielať vzorky.',
    'banks_default' => 'Podporované: ASN, ING',

    'file_ready' => '· ✓ pripravené',

    'skip' => 'Preskočiť tento krok',
    'continue' => 'Pokračovať →',

    'errors' => [
        'file_required' => 'Najprv presuň súbor s výpisom do poľa.',
        'file_max' => 'Tento súbor je príliš veľký. Presuň výpis menší než 10 MB.',
        'file_extensions' => 'Tento súbor nevyzerá ako bankový výpis. Presuň súbor CAMT.053 XML, CSV alebo MT940.',
        'pick_bank' => 'Skôr než budeš pokračovať, vyber banku, ktorá vyexportovala tvoj CSV.',
        'unreadable' => 'Tento súbor sa nepodarilo prečítať. Úplná chyba je v /dev/logs.',
    ],
];
