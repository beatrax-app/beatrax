<?php

declare(strict_types=1);

return [
    'page_title' => 'Afstemning',
    'heading' => 'Afstemning',
    'intro' => 'Bekræft en kontos saldo ifølge kontoudtoget mod dine bogførte transaktioner. Når de stemmer, afslutter du afstemningen for at låse rækkerne fast.',

    'account' => 'Konto',
    'choose_account' => 'Vælg en konto…',
    'statement_date' => 'Kontoudtogets dato',
    'statement_balance' => 'Saldo ifølge kontoudtog (:symbol)',
    'balance_help' => 'Udfyldes på forhånd fra dit senest importerede kontoudtog, når det er muligt — negativ ved gæld, og redigerbar i begge tilfælde.',

    'cleared_balance' => 'Bogført saldo',
    'statement_target' => 'Målsaldo ifølge kontoudtog',
    'difference' => 'Forskel',

    'pill' => [
        'choose_account' => 'vælg en konto',
        'choose_date' => 'vælg en kontoudtogsdato',
        'enter_balance' => 'indtast en saldo ifølge kontoudtoget',
        'matched' => 'stemmer — :amount',
        'discrepancy' => 'afvigelse — :amount',
        'reconciled_through' => 'afstemt til og med :date',
    ],

    'mismatch_html' => 'Saldoen ifølge kontoudtoget stemmer endnu ikke med din bogførte saldo. Slå bogførte rækker til og fra på <a href=":url" class="underline">transaktionslisten</a>, eller justér den indtastede saldo, indtil forskellen når nul — dette forløb opretter aldrig en udligningspostering.',
    'unreachable_no_baseline_html' => 'Ingen kombination af rækker kan bringe denne forskel til nul. Denne konto har ingen startsaldo registreret, så dens saldo måles fra nul. Importér det kontoudtog, kontoen åbner med, eller angiv startsaldoen under <a href=":url" class="underline">Indstillinger</a>.',
    'unreachable' => 'Ingen kombination af rækker kan bringe denne forskel til nul: den ligger uden for intervallet af alle rækker på denne konto frem til den angivne dato. Kontrollér kontoudtogets dato og den indtastede saldo.',

    'check' => 'Kontrollér',
    'complete' => 'Afslut afstemningen',
    'complete_unavailable' => 'Der er ikke mere at låse frem til denne dato — markér flere rækker som bogførte, eller vælg en senere kontoudtogsdato.',

    'errors' => [
        'choose_account' => 'Vælg en konto først.',
        'invalid_balance_date' => 'Indtast en gyldig saldo ifølge kontoudtoget og en gyldig dato.',
        'mismatch' => 'Saldoen ifølge kontoudtoget stemmer endnu ikke med den bogførte saldo — justér bogførte rækker eller den indtastede saldo, indtil forskellen er nul.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Der er intet at låse for denne kontoudtogsdato.',
        'complete' => 'Afstemningen er færdig — :count række er låst.|Afstemningen er færdig — :count rækker er låst.',
    ],
];
