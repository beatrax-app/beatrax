<?php

declare(strict_types=1);

return [
    'page_title' => 'Avstemming',
    'heading' => 'Avstemming',
    'intro' => 'Bekreft saldoen på kontoutskriften for en konto mot de bokførte transaksjonene dine. Når de stemmer, fullfører du avstemmingen for å låse radene.',

    'account' => 'Konto',
    'choose_account' => 'Velg en konto…',
    'statement_date' => 'Kontoutskriftens dato',
    'statement_balance' => 'Saldo ifølge kontoutskrift (:symbol)',
    'balance_help' => 'Fylles ut på forhånd fra den sist importerte kontoutskriften din når det er mulig — negativ ved gjeld, og redigerbar uansett.',

    'cleared_balance' => 'Bokført saldo',
    'statement_target' => 'Målsaldo ifølge kontoutskrift',
    'difference' => 'Differanse',

    'pill' => [
        'choose_account' => 'velg en konto',
        'choose_date' => 'velg en kontoutskriftsdato',
        'enter_balance' => 'angi en saldo fra kontoutskriften',
        'matched' => 'stemmer — :amount',
        'discrepancy' => 'avvik — :amount',
        'reconciled_through' => 'avstemt til og med :date',
    ],

    'mismatch_html' => 'Saldoen ifølge kontoutskriften stemmer ennå ikke med den bokførte saldoen din. Slå bokførte rader av og på i <a href=":url" class="underline">transaksjonslisten</a>, eller juster saldoen du har angitt, til differansen når null — denne flyten oppretter aldri en utjevnende postering.',
    'unreachable_no_baseline_html' => 'Ingen kombinasjon av rader kan bringe denne differansen til null. Denne kontoen har ingen inngående saldo registrert, så saldoen måles fra null. Importer kontoutskriften kontoen åpner med, eller angi inngående saldo under <a href=":url" class="underline">Innstillinger</a>.',
    'unreachable' => 'Ingen kombinasjon av rader kan bringe denne differansen til null: den ligger utenfor området til alle radene på denne kontoen fram til den oppgitte datoen. Kontroller kontoutskriftens dato og saldoen du oppga.',

    'check' => 'Kontroller',
    'complete' => 'Fullfør avstemmingen',
    'complete_unavailable' => 'Det er ikke mer å låse fram til denne datoen — merk flere rader som bokførte, eller velg en senere kontoutskriftsdato.',

    'errors' => [
        'choose_account' => 'Velg en konto først.',
        'invalid_balance_date' => 'Angi en gyldig saldo fra kontoutskriften og en gyldig dato.',
        'mismatch' => 'Saldoen ifølge kontoutskriften stemmer ennå ikke med den bokførte saldoen — juster bokførte rader eller saldoen du har angitt, til differansen er null.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Ingenting å låse for denne datoen på kontoutskriften.',
        'complete' => 'Avstemmingen er fullført — :count rad er låst.|Avstemmingen er fullført — :count rader er låst.',
    ],
];
