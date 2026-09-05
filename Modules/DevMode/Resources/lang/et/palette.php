<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Tipi, et otsida vaateid, käske ja toiminguid. Sulgemiseks vajuta Esc.',
    'search_aria' => 'Tipi, et otsida vaateid, käske ja toiminguid',
    'dialog_aria' => 'Käsupalett',
    'token_suggest_aria' => 'Märgendi soovitused',
    'rail_view' => 'Vaade',
    'rail_dev' => 'Arendus',
    'rail_action' => 'Toiming',
    'rail_recent' => 'Hiljutised',
    'no_recent' => 'Hiljutisi valikuid veel pole.',
    'section_transactions' => 'Tehingud',
    'section_counterparties' => 'Vastaspooled',
    'section_categories' => 'Kategooriad',
    'section_goals_recurring' => 'Eesmärgid ja korduvmaksed',
    'no_name' => '(nimeta)',
    'see_all' => 'Vaata :count tulemust →|Vaata kõiki :count tulemust →',
    'no_transactions' => 'Ükski tehing ei vasta päringule „:query“',
    'source_txn' => 'tehing',
    'source_counterparty' => 'vastaspool',
    'source_category' => 'kategooria',
    'results_aria' => 'Tulemused',
    'no_results' => 'Tulemusi pole.',
    'foot_navigate' => 'liigu',
    'foot_select' => 'vali',
    'foot_close' => 'sulge',
    'close_aria' => 'Sulge otsing',
    'close_caption' => 'Sulge',
    'foot_try' => 'Proovi',
    'results' => ':count tulemus|:count tulemust',

    'action' => [
        'run_import' => ['label' => 'Käivita import', 'hint' => 'Ava impordiviisard'],
        'scan_email' => ['label' => 'Ava postkastid', 'hint' => 'Sinu ühendatud postkastid'],
        'open_profile' => ['label' => 'Ava profiil', 'hint' => 'Seaded — konto ja eelistused'],
        'toggle_theme' => ['label' => 'Ava välimuse seaded', 'hint' => 'Hele, tume või süsteemne'],
    ],

    'run_command' => 'Käivita :command',

    'nav' => [
        'overview' => ['label' => 'Arenduse ülevaade', 'hint' => 'Süsteemiplaadid + hiljutised käivitused'],
        'artisan' => ['label' => 'Artisani käivitaja', 'hint' => 'Käivita lubatud käsud'],
        'audit' => ['label' => 'Arendusrežiimi auditilogi', 'hint' => 'Sinu arendusrežiimi toimingud'],
        'logs' => ['label' => 'Logide jälgija', 'hint' => 'laravel-*.log otsevoog'],
        'queue' => ['label' => 'Järjekorra inspektor', 'hint' => 'Ootel / ebaõnnestunud / partiid'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Süsteemikontrollid'],
        'sql' => ['label' => 'SQL-paneel', 'hint' => 'Ainult SELECT-sirvija'],
        'system' => ['label' => 'Süsteemi hetktõmmis', 'hint' => 'Keskkond + asukohad + konfiguratsioon'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Sisseehitatud järjekorra töölaud'],
        'sync_health' => ['label' => 'Sünkroonimise seisund', 'hint' => 'Karantiini pandud või vahele jäetud ühendamised'],
    ],
];
