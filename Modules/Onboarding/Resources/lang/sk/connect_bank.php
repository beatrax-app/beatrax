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
    'drop_lead_csv_layout' => 'Sem presuň CSV z :layout',
    'drop_lead_pick_bank' => 'Vyber banku, ktorá vyexportovala tvoj CSV — bez toho ho nedokážeme správne prečítať.',
    'drop_lead_default' => 'Sem presuň súbor s výpisom',
    'browse_file' => 'alebo vyber súbor z disku',

    'format_help_camt053' => 'CAMT.053 je výpis vo formáte XML — hľadaj ho v internetbankingu pri výpisoch alebo stiahnutiach.',
    'format_help_mt940' => 'MT940 je textový výpis ponúkaný ako .sta alebo .940 vedľa súborov XML a CSV.',
    'format_help_csv' => 'CSV je export do tabuľky. Každá banka radí stĺpce inak, preto vyber zodpovedajúce rozloženie. Ak tvoje v zozname nie je, požiadaj banku o CAMT.053 alebo MT940.',

    'account_name_default' => 'Bankový účet',
    'account_name_layout' => 'Účet :layout',

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
