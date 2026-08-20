<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenarijaus redaktorius — :name',
    'rename_aria' => 'Pervadinti scenarijų',
    'save' => 'Išsaugoti',
    'save_changes' => 'Išsaugoti pakeitimus',
    'cancel' => 'Atšaukti',
    'rename' => 'Pervadinti',
    'confirm_delete' => 'Patvirtinti trynimą',
    'delete_scenario' => 'Ištrinti scenarijų',
    'delete_confirm' => 'Ištrinti šį scenarijų?',

    'mutations_count' => 'Pakeitimai (:count)',
    'no_mutations' => 'Kol kas pakeitimų nėra. Pridėk vieną žemiau ir pamatysi, kaip šis scenarijus atrodo palyginti su baziniu.',
    'editing' => 'Redaguojama — :kind',
    'edit' => 'Redaguoti',
    'remove' => 'Pašalinti',

    'add_mutation' => '+ Pridėti pakeitimą',
    'add_to_scenario' => 'Pridėti prie scenarijaus',
    'pick_kind' => 'Pasirink pakeitimo tipą:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Nutraukti seriją',
            'desc' => 'Pašalinti kiekvieną prognozuojamą patvirtintos serijos įvykį.',
        ],
        'add_one_off' => [
            'title' => 'Pridėti vienkartinį mokėjimą ar įplauką',
            'desc' => 'Vienas hipotetinis įvykis konkrečią dieną.',
        ],
        'add_recurring' => [
            'title' => 'Pridėti pasikartojančią seriją',
            'desc' => 'Hipotetinė nauja prenumerata arba pajamų srautas.',
        ],
        'change_series_amount' => [
            'title' => 'Pakeisti serijos sumą',
            'desc' => 'Modeliuoti esamos serijos kainos kilimą arba kritimą.',
        ],
        'shift_series_date' => [
            'title' => 'Pastumti serijos datą',
            'desc' => 'Perkelti kitą arba visus tolesnius įvykius į priekį.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Nutraukiama serija',
        'pick_series' => '— pasirink seriją —',
        'date' => 'Data',
        'amount' => 'Suma',
        'currency' => 'Valiuta',
        'direction' => 'Kryptis',
        'expense_long' => 'Išlaidos (pinigai išeina)',
        'income_long' => 'Pajamos (pinigai ateina)',
        'note' => 'Pastaba (neprivaloma)',
        'start_date' => 'Pradžios data',
        'expense' => 'Išlaidos',
        'income' => 'Pajamos',
        'cadence' => 'Dažnumas',
        'cadence_weekly' => 'Kas savaitę',
        'cadence_monthly' => 'Kas mėnesį',
        'cadence_quarterly' => 'Kas ketvirtį',
        'cadence_yearly' => 'Kas metus',
        'series' => 'Serija',
        'new_amount' => 'Nauja suma',
        'new_next_date' => 'Nauja kita data',
        'scope' => 'Apimtis',
        'scope_legend' => 'Kuriuos įvykius perkelti',
        'scope_next' => 'Tik kitas įvykis',
        'scope_all' => 'Visi tolesni įvykiai',
    ],

    'whatif' => [
        'trigger' => 'Modeliuoti „kas, jeigu“',
        'menu_aria' => 'Modeliuoti „kas, jeigu“ — :name',
        'model_cancellation' => 'Modeliuoti nutraukimą',
        'model_amount_change' => 'Modeliuoti sumos pakeitimą…',
        'amount_dialog_aria' => 'Modeliuoti :name sumos pakeitimą',
        'current_amount' => 'Dabartinė suma',
        'new_amount' => 'Nauja suma',
    ],

    'summary' => [
        'cancel' => 'Nutraukti :name',
        'series_fallback' => 'serija #:id',
        'one_off' => ':amount :currency :date',
        'recurring' => ':amount :currency :cadence nuo :date',
        'change_amount' => ':name: nauja suma :amount',
        'shift' => ':name: pastumti :scope į :date',
        'scope_all' => 'visus tolesnius',
        'scope_next' => 'kitą',
    ],

    'toast' => [
        'created' => 'Scenarijus „:name“ sukurtas.',
        'deleted' => 'Scenarijus ištrintas.',
        'renamed' => 'Scenarijus pervadintas.',
        'mutation_added' => 'Pakeitimas pridėtas.',
        'mutation_updated' => 'Pakeitimas atnaujintas.',
        'mutation_removed' => 'Pakeitimas pašalintas. Anuliuoti',
    ],

    'errors' => [
        'name_empty' => 'Scenarijaus pavadinimas negali būti tuščias.',
        'name_too_long' => 'Scenarijaus pavadinimas turi būti ne ilgesnis nei :max simbolių.',
        'name_taken' => 'Scenarijus tokiu pavadinimu jau yra.',
        'pick_kind_first' => 'Pirmiausia pasirink pakeitimo tipą.',
        'amount_positive' => 'Suma turi būti teigiamas skaičius.',
    ],
];
