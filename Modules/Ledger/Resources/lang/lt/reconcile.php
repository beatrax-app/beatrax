<?php

declare(strict_types=1);

return [
    'page_title' => 'Suderinimas',
    'heading' => 'Suderinimas',
    'intro' => 'Palygink sąskaitos išrašo likutį su įvykdytomis operacijomis. Kai jie sutaps, užbaik suderinimą, kad tos eilutės būtų užrakintos.',

    'account' => 'Sąskaita',
    'choose_account' => 'Pasirink sąskaitą…',
    'statement_date' => 'Išrašo data',
    'statement_balance' => 'Išrašo likutis (:symbol)',
    'balance_help' => 'Užpildoma iš naujausio importuoto išrašo, kai jis yra — neigiama reikšmė reiškia skolą, bet kuriuo atveju galima redaguoti.',

    'cleared_balance' => 'Įvykdytų operacijų likutis',
    'statement_target' => 'Išrašo tikslinis likutis',
    'difference' => 'Skirtumas',

    'pill' => [
        'choose_account' => 'pasirink sąskaitą',
        'choose_date' => 'pasirink išrašo datą',
        'enter_balance' => 'įvesk išrašo likutį',
        'matched' => 'sutampa — :amount',
        'discrepancy' => 'neatitikimas — :amount',
        'reconciled_through' => 'suderinta iki :date',
    ],

    'mismatch_html' => 'Išrašo likutis kol kas nesutampa su įvykdytų operacijų likučiu. Perjunk įvykdytas eilutes <a href=":url" class="underline">operacijų sąraše</a> arba pakoreguok įvestą likutį, kol skirtumas taps nulinis — šis procesas niekada nesukuria balansuojančio įrašo.',
    'unreachable_no_baseline_html' => 'Jokia eilučių kombinacija negali sumažinti šio skirtumo iki nulio. Šiai sąskaitai nėra užfiksuotas pradinis likutis, todėl jos likutis skaičiuojamas nuo nulio. Importuok išrašą, kuriuo sąskaita atidaroma, arba nustatyk pradinį likutį <a href=":url" class="underline">Nustatymuose</a>.',
    'unreachable' => 'Jokia eilučių kombinacija negali sumažinti šio skirtumo iki nulio: jis yra už visų šios sąskaitos eilučių intervalo ribų iki nurodytos datos. Patikrink išrašo datą ir įvestą likutį.',

    'check' => 'Tikrinti',
    'complete' => 'Užbaigti suderinimą',
    'complete_unavailable' => 'Iki šios datos daugiau nėra ko užrakinti — pažymėk daugiau eilučių kaip įvykdytas arba pasirink vėlesnę išrašo datą.',

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
