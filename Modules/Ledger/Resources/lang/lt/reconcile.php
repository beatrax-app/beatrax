<?php

declare(strict_types=1);

return [
    'page_title' => 'Suderinimas',
    'heading' => 'Suderinimas',
    'intro' => 'Palygink sąskaitos išrašo likutį su įvykdytomis operacijomis. Kai jie sutaps, užbaik suderinimą, kad tos eilutės būtų užrakintos.',

    'account' => 'Sąskaita',
    'choose_account' => 'Pasirink sąskaitą…',
    'statement_date' => 'Išrašo data',
    'statement_balance' => 'Išrašo likutis (€)',
    'balance_help' => 'Užpildoma iš naujausio importuoto išrašo, kai jis yra — neigiama reikšmė reiškia skolą, bet kuriuo atveju galima redaguoti.',

    'cleared_balance' => 'Įvykdytų operacijų likutis',
    'statement_target' => 'Išrašo tikslinis likutis',
    'difference' => 'Skirtumas',

    'pill' => [
        'choose_account' => 'pasirink sąskaitą',
        'enter_balance' => 'įvesk išrašo likutį',
        'matched' => 'sutampa — :amount',
        'discrepancy' => 'neatitikimas — :amount',
    ],

    'mismatch_html' => 'Išrašo likutis kol kas nesutampa su įvykdytų operacijų likučiu. Perjunk įvykdytas eilutes <a href=":url" class="underline">operacijų sąraše</a> arba pakoreguok įvestą likutį, kol skirtumas taps nulinis — šis procesas niekada nesukuria balansuojančio įrašo.',

    'check' => 'Tikrinti',
    'complete' => 'Užbaigti suderinimą',

    'errors' => [
        'choose_account' => 'Pirmiausia pasirink sąskaitą.',
        'invalid_balance_date' => 'Įvesk tinkamą išrašo likutį ir datą.',
        'mismatch' => 'Išrašo likutis kol kas nesutampa su įvykdytų operacijų likučiu — koreguok įvykdytas eilutes arba įvestą likutį, kol skirtumas taps nulinis.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Šiai išrašo datai nėra ko užrakinti.',
        'complete' => 'Suderinimas baigtas — užrakinta :count eilutė.|Suderinimas baigtas — užrakintos :count eilutės.|Suderinimas baigtas — užrakinta :count eilučių.',
    ],
];
