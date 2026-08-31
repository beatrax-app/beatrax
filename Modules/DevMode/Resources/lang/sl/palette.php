<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Tipkaj za iskanje pogledov, ukazov in dejanj. Za zapiranje pritisni Esc.',
    'search_aria' => 'Tipkaj za iskanje pogledov, ukazov in dejanj',
    'dialog_aria' => 'Ukazna paleta',
    'token_suggest_aria' => 'Predlogi žetonov',
    'rail_view' => 'Pogled',
    'rail_dev' => 'Dev',
    'rail_action' => 'Dejanje',
    'rail_recent' => 'Nedavno',
    'no_recent' => 'Nedavnih izbir še ni.',
    'section_transactions' => 'Transakcije',
    'section_counterparties' => 'Nasprotne stranke',
    'section_categories' => 'Kategorije',
    'section_goals_recurring' => 'Cilji in ponavljajoče',
    'no_name' => '(brez imena)',
    'see_all' => 'Prikaži :count zadetek →|Prikaži :count zadetka →|Prikaži :count zadetke →|Prikaži vseh :count zadetkov →',
    'no_transactions' => 'Nobena transakcija se ne ujema z ":query"',
    'source_txn' => 'transakcija',
    'source_counterparty' => 'nasprotna stranka',
    'source_category' => 'kategorija',
    'results_aria' => 'Zadetki',
    'no_results' => 'Ni zadetkov.',
    'foot_navigate' => 'premikanje',
    'foot_select' => 'izbira',
    'foot_close' => 'zapiranje',
    'close_aria' => 'Zapri iskanje',
    'close_caption' => 'Zapri',
    'foot_try' => 'Poskusi',
    'results' => ':count zadetek|:count zadetka|:count zadetki|:count zadetkov',

    'action' => [
        'run_import' => ['label' => 'Zaženi uvoz', 'hint' => 'Odpri čarovnika za uvoz'],
        'scan_email' => ['label' => 'Preglej e-pošto zdaj', 'hint' => 'Takoj zaženi sinhronizacijo nabiralnika'],
        // i18n-review: sl · action.open_profile.hint — «nastavitve» is this locale's word for
        // both Settings and preferences, so the second half says «možnosti». Confirm that
        // reads as preferences rather than as feature options.
        'open_profile' => ['label' => 'Odpri profil', 'hint' => 'Nastavitve — račun in možnosti'],
        'toggle_theme' => ['label' => 'Zamenjaj temo', 'hint' => 'Preklop med svetlo in temno temo'],
    ],

    'run_command' => 'Zaženi :command',

    'nav' => [
        'overview' => ['label' => 'Razvijalski pregled', 'hint' => 'Sistemske ploščice + nedavni zagoni'],
        'artisan' => ['label' => 'Zaganjalnik Artisan', 'hint' => 'Zaganjanje ukazov s seznama dovoljenih'],
        'audit' => ['label' => 'Revizijski dnevnik', 'hint' => 'Vsako dejanje v razvijalskem načinu'],
        'logs' => ['label' => 'Sledenje dnevniku', 'hint' => 'Sprotni tok laravel-*.log'],
        'queue' => ['label' => 'Pregledovalnik čakalne vrste', 'hint' => 'V čakanju / neuspela / paketi'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sistemska preverjanja'],
        'sql' => ['label' => 'Plošča SQL', 'hint' => 'Pregledovalnik samo za stavke SELECT'],
        'system' => ['label' => 'Posnetek sistema', 'hint' => 'Okolje + poti + konfiguracija'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Vgrajena nadzorna plošča čakalne vrste'],
        'sync_health' => ['label' => 'Stanje sinhronizacije', 'hint' => 'Operacije združevanja v karanteni / preskočene'],
    ],
];
