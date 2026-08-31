<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Kucaj da pretražiš prikaze, komande i radnje. Pritisni Esc da zatvoriš.',
    'search_aria' => 'Kucaj da pretražiš prikaze, komande i radnje',
    'dialog_aria' => 'Paleta komandi',
    'token_suggest_aria' => 'Predlozi tokena',
    'rail_view' => 'Prikaz',
    'rail_dev' => 'Dev',
    'rail_action' => 'Radnja',
    'rail_recent' => 'Nedavno',
    'no_recent' => 'Još nema nedavnih izbora.',
    'section_transactions' => 'Transakcije',
    'section_counterparties' => 'Druge strane',
    'section_categories' => 'Kategorije',
    'section_goals_recurring' => 'Ciljevi i ponavljajuće',
    'no_name' => '(bez naziva)',
    // i18n-review: sr · see_all — same call as the Croatian file: the quantifier is
    // carried only by the 5+ arm, because sva at 2-4 against svih at 5+ is the part
    // a numeral does not settle. Both files want the same answer.
    'see_all' => 'Prikaži :count rezultat →|Prikaži :count rezultata →|Prikaži svih :count rezultata →',
    'no_transactions' => 'Nijedna transakcija ne odgovara upitu ":query"',
    'source_txn' => 'transakcija',
    'source_counterparty' => 'druga strana',
    'source_category' => 'kategorija',
    'results_aria' => 'Rezultati',
    'no_results' => 'Nema rezultata.',
    'foot_navigate' => 'kretanje',
    'foot_select' => 'izbor',
    'foot_close' => 'zatvaranje',
    'close_aria' => 'Zatvori pretragu',
    'close_caption' => 'Zatvori',
    'foot_try' => 'Probaj',
    'results' => ':count rezultat|:count rezultata|:count rezultata',

    'action' => [
        'run_import' => ['label' => 'Pokreni uvoz', 'hint' => 'Otvori čarobnjak za uvoz'],
        'scan_email' => ['label' => 'Skeniraj e-poštu sada', 'hint' => 'Odmah pokreni sinhronizaciju sandučeta'],
        // i18n-review: sr · action.open_profile.hint — «podešavanja» covers both Settings and
        // preferences here, so the second half says «opcije». Confirm that reads as
        // preferences rather than as feature options.
        'open_profile' => ['label' => 'Otvori profil', 'hint' => 'Podešavanja — nalog i opcije'],
        'toggle_theme' => ['label' => 'Promeni temu', 'hint' => 'Prebaci između svetle i tamne teme'],
    ],

    'run_command' => 'Pokreni :command',

    'nav' => [
        // i18n-review: sr · nav.overview.label — «Dev» stays attributive without a
        // hyphen, matching «SQL upit» in audit.php. A native may prefer «Pregled dev
        // konzole», which is clearer but long for a palette row.
        'overview' => ['label' => 'Dev pregled', 'hint' => 'Sistemske pločice + nedavna pokretanja'],
        'artisan' => ['label' => 'Artisan pokretač', 'hint' => 'Pokreni komande sa liste dozvoljenih'],
        'audit' => ['label' => 'Dev revizioni log', 'hint' => 'Svaka radnja u dev režimu'],
        // i18n-review: sr · nav.logs — sr has no settled noun for a log tailer, so the
        // label names the act of following the file and the hint carries «uživo» from
        // logs.php, so the pair reads as one feature.
        'logs' => ['label' => 'Praćenje logova', 'hint' => 'Tok datoteke laravel-*.log uživo'],
        'queue' => ['label' => 'Inspektor reda čekanja', 'hint' => 'Na čekanju / neuspeli / grupe'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sistemske provere'],
        'sql' => ['label' => 'SQL panel', 'hint' => 'Pregledanje isključivo tipa SELECT'],
        'system' => ['label' => 'Snimak sistema', 'hint' => 'Okruženje + putanje + konfiguracija'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Ugrađena kontrolna tabla reda čekanja'],
        'sync_health' => ['label' => 'Stanje sinhronizacije', 'hint' => 'Karantenirane / preskočene operacije spajanja'],
    ],
];
