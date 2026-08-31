<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Tipkaj za pretragu prikaza, naredbi i radnji. Pritisni Esc za zatvaranje.',
    'search_aria' => 'Tipkaj za pretragu prikaza, naredbi i radnji',
    'dialog_aria' => 'Paleta naredbi',
    'token_suggest_aria' => 'Prijedlozi tokena',
    'rail_view' => 'Prikaz',
    'rail_dev' => 'Dev',
    'rail_action' => 'Radnja',
    'rail_recent' => 'Nedavno',
    'no_recent' => 'Još nema nedavnih odabira.',
    'section_transactions' => 'Transakcije',
    'section_counterparties' => 'Protustranke',
    'section_categories' => 'Kategorije',
    'section_goals_recurring' => 'Ciljevi i ponavljajuće',
    'no_name' => '(bez naziva)',
    // i18n-review: hr · see_all — the affordance is "see them ALL", and only the
    // third arm still says so: sva/svih agree differently at 2-4 and at 5+, so the
    // first two arms drop the quantifier rather than risk the wrong one. A native
    // reader decides whether sva :count rezultata reads, or whether the drop is right.
    'see_all' => 'Prikaži :count rezultat →|Prikaži :count rezultata →|Prikaži svih :count rezultata →',
    'no_transactions' => 'Nijedna transakcija ne odgovara upitu ":query"',
    'source_txn' => 'transakcija',
    'source_counterparty' => 'protustranka',
    'source_category' => 'kategorija',
    'results_aria' => 'Rezultati',
    'no_results' => 'Nema rezultata.',
    'foot_navigate' => 'kretanje',
    'foot_select' => 'odabir',
    'foot_close' => 'zatvaranje',
    'close_aria' => 'Zatvori pretraživanje',
    'close_caption' => 'Zatvori',
    'foot_try' => 'Pokušaj',
    'results' => ':count rezultat|:count rezultata|:count rezultata',

    'action' => [
        'run_import' => ['label' => 'Pokreni uvoz', 'hint' => 'Otvori čarobnjak za uvoz'],
        'scan_email' => ['label' => 'Skeniraj e-poštu sada', 'hint' => 'Odmah pokreni sinkronizaciju sandučića'],
        // i18n-review: hr · action.open_profile.hint — «postavke» is this locale's word for
        // both Settings and preferences, so the second half says «mogućnosti». Confirm that
        // reads as preferences and not as feature options.
        'open_profile' => ['label' => 'Otvori profil', 'hint' => 'Postavke — račun i mogućnosti'],
        'toggle_theme' => ['label' => 'Promijeni temu', 'hint' => 'Prebaci između svijetle i tamne teme'],
    ],

    'run_command' => 'Pokreni :command',

    'nav' => [
        'overview' => ['label' => 'Razvojni pregled', 'hint' => 'Pločice sustava + nedavna pokretanja'],
        'artisan' => ['label' => 'Artisan pokretač', 'hint' => 'Pokretanje dopuštenih naredbi'],
        'audit' => ['label' => 'Razvojni zapisnik revizije', 'hint' => 'Svaka radnja u razvojnom načinu rada'],
        'logs' => ['label' => 'Praćenje zapisnika', 'hint' => 'Prijenos uživo datoteke laravel-*.log'],
        'queue' => ['label' => 'Inspektor reda čekanja', 'hint' => 'Na čekanju / neuspjeli / grupe'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Provjere sustava'],
        'sql' => ['label' => 'SQL panel', 'hint' => 'Preglednik samo za SELECT'],
        'system' => ['label' => 'Snimka sustava', 'hint' => 'Okruženje + putanje + konfiguracija'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Ugrađena nadzorna ploča reda čekanja'],
        'sync_health' => ['label' => 'Stanje sinkronizacije', 'hint' => 'Operacije spajanja u karanteni ili preskočene'],
    ],
];
