<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Urejevalnik scenarijev — :name',
    'rename_aria' => 'Preimenuj scenarij',
    'save' => 'Shrani',
    'save_changes' => 'Shrani spremembe',
    'cancel' => 'Prekliči',
    'rename' => 'Preimenuj',
    'confirm_delete' => 'Potrdi brisanje',
    'delete_scenario' => 'Izbriši scenarij',
    'delete_confirm' => 'Izbrišem ta scenarij?',

    'mutations_count' => 'Spremembe (:count)',
    'no_mutations' => 'Sprememb še ni. Spodaj dodaj eno, da vidiš, kako se ta scenarij primerja s tvojo izhodiščno napovedjo.',
    'editing' => 'Urejanje — :kind',
    'edit' => 'Uredi',
    'remove' => 'Odstrani',

    'add_mutation' => '+ Dodaj spremembo',
    'add_to_scenario' => 'Dodaj v scenarij',
    'pick_kind' => 'Izberi vrsto spremembe:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Prekliči serijo',
            'desc' => 'Izpusti vsako napovedano pojavitev odobrene serije.',
        ],
        'add_one_off' => [
            'title' => 'Dodaj enkratno bremenitev ali odobritev',
            'desc' => 'En hipotetičen dogodek na določen datum.',
        ],
        'add_recurring' => [
            'title' => 'Dodaj ponavljajočo se serijo',
            'desc' => 'Hipotetična nova naročnina ali vir prihodka.',
        ],
        'change_series_amount' => [
            'title' => 'Spremeni znesek serije',
            'desc' => 'Modeliraj podražitev ali pocenitev obstoječe serije.',
        ],
        'shift_series_date' => [
            'title' => 'Premakni datum serije',
            'desc' => 'Premakni naslednjo ali vse nadaljnje pojavitve.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serija za preklic',
        'pick_series' => '— izberi serijo —',
        'date' => 'Datum',
        'amount' => 'Znesek',
        'currency' => 'Valuta',
        'direction' => 'Smer',
        'expense_long' => 'Strošek (odliv)',
        'income_long' => 'Prihodek (priliv)',
        'note' => 'Opomba (neobvezno)',
        'start_date' => 'Datum začetka',
        'expense' => 'Strošek',
        'income' => 'Prihodek',
        'cadence' => 'Pogostost',
        'cadence_weekly' => 'Tedensko',
        'cadence_monthly' => 'Mesečno',
        'cadence_quarterly' => 'Četrtletno',
        'cadence_yearly' => 'Letno',
        'series' => 'Serija',
        'new_amount' => 'Nov znesek',
        'new_next_date' => 'Nov naslednji datum',
        'scope' => 'Obseg',
        'scope_legend' => 'Katere pojavitve premakniti',
        'scope_next' => 'Samo naslednja pojavitev',
        'scope_all' => 'Vse nadaljnje pojavitve',
    ],

    'whatif' => [
        'trigger' => 'Modeliraj „kaj če“',
        'menu_aria' => 'Modeliraj „kaj če“ za :name',
        'model_cancellation' => 'Modeliraj preklic',
        'model_amount_change' => 'Modeliraj spremembo zneska…',
        'amount_dialog_aria' => 'Modeliraj spremembo zneska za :name',
        'current_amount' => 'Trenutni znesek',
        'new_amount' => 'Nov znesek',
    ],

    'series_name_fallback' => 'serija',

    'summary' => [
        'cancel' => 'Prekliči :name',
        'series_fallback' => 'serija št. :id',
        'one_off' => ':amount :currency dne :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: nov znesek :amount',
        'shift' => ':name: premik :scope na :date',
        'scope_all' => 'vseh nadaljnjih',
        'scope_next' => 'naslednje',
    ],

    'toast' => [
        'created' => 'Scenarij „:name“ je ustvarjen.',
        'deleted' => 'Scenarij je izbrisan.',
        'renamed' => 'Scenarij je preimenovan.',
        'mutation_added' => 'Sprememba je dodana.',
        'mutation_updated' => 'Sprememba je posodobljena.',
        'mutation_removed' => 'Sprememba je odstranjena. Razveljavi',
    ],

    'errors' => [
        'name_empty' => 'Ime scenarija ne sme biti prazno.',
        'name_too_long' => 'Ime scenarija sme imeti največ :max znakov.',
        'name_taken' => 'Scenarij s tem imenom že obstaja.',
        'pick_kind_first' => 'Najprej izberi vrsto spremembe.',
        'amount_positive' => 'Znesek mora biti pozitivno število.',
    ],
];
