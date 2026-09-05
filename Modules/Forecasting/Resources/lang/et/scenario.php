<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Stsenaariumi redaktor — :name',
    'rename_aria' => 'Nimeta stsenaarium ümber',
    'save' => 'Salvesta',
    'save_changes' => 'Salvesta muudatused',
    'cancel' => 'Tühista',
    'rename' => 'Nimeta ümber',
    'confirm_delete' => 'Kinnita kustutamine',
    'delete_scenario' => 'Kustuta stsenaarium',
    'delete_confirm' => 'Kas kustutada see stsenaarium?',

    'mutations_count' => 'Muudatused (:count)',
    'no_mutations' => 'Muudatusi veel pole. Lisa allpool üks, et näha, kuidas see stsenaarium sinu baasjoonega võrreldes välja näeb.',
    'editing' => 'Muudan — :kind',
    'edit' => 'Muuda',
    'remove' => 'Eemalda',

    'add_mutation' => '+ Lisa muudatus',
    'add_to_scenario' => 'Lisa stsenaariumi',
    'pick_kind' => 'Vali muudatuse liik:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Tühista seeria',
            'desc' => 'Jäta kinnitatud seeria iga prognoositud kord välja.',
        ],
        'add_one_off' => [
            'title' => 'Lisa ühekordne makse või laekumine',
            'desc' => 'Üksik oletuslik sündmus kindlal kuupäeval.',
        ],
        'add_recurring' => [
            'title' => 'Lisa korduvmaksete seeria',
            'desc' => 'Oletuslik uus tellimus või tuluvoog.',
        ],
        'change_series_amount' => [
            'title' => 'Muuda seeria summat',
            'desc' => 'Modelleeri olemasoleva seeria hinnatõusu või -langust.',
        ],
        'shift_series_date' => [
            'title' => 'Nihuta seeria kuupäeva',
            'desc' => 'Nihuta järgmine või kõik järgnevad korrad teisele kuupäevale.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Tühistatav seeria',
        'pick_series' => '— vali seeria —',
        'date' => 'Kuupäev',
        'amount' => 'Summa',
        'currency' => 'Valuuta',
        'direction' => 'Suund',
        'expense_long' => 'Kulu (raha välja)',
        'income_long' => 'Tulu (raha sisse)',
        'note' => 'Märkus (valikuline)',
        'start_date' => 'Alguskuupäev',
        'expense' => 'Kulu',
        'income' => 'Tulu',
        'cadence' => 'Sagedus',
        'cadence_weekly' => 'Iga nädal',
        'cadence_monthly' => 'Iga kuu',
        'cadence_quarterly' => 'Iga kvartal',
        'cadence_yearly' => 'Iga aasta',
        'series' => 'Seeria',
        'new_amount' => 'Uus summa',
        'new_next_date' => 'Uus järgmine kuupäev',
        'scope' => 'Ulatus',
        'scope_legend' => 'Milliseid kordi nihutada',
        'scope_next' => 'Ainult järgmine kord',
        'scope_all' => 'Kõik järgnevad korrad',
    ],

    'whatif' => [
        'trigger' => 'Modelleeri „mis siis, kui“',
        'menu_aria' => 'Modelleeri „mis siis, kui“ seeria :name jaoks',
        'model_cancellation' => 'Modelleeri ülesütlemine',
        'model_amount_change' => 'Modelleeri summa muutus…',
        'amount_dialog_aria' => 'Modelleeri seeria :name summa muutus',
        'current_amount' => 'Praegune summa',
        'new_amount' => 'Uus summa',
    ],

    'series_name_fallback' => 'seeria',

    'template' => [
        'cancel' => 'Tühista :name',
        'change_amount' => 'Muuda :name summat',
    ],

    'summary' => [
        'cancel' => 'Tühista :name',
        'series_fallback' => 'seeria #:id',
        'one_off' => ':amount :currency kuupäeval :date',
        'recurring' => ':amount :currency :cadence alates :date',
        'change_amount' => ':name: uus summa :amount',
        'shift' => ':name: nihuta :scope kuupäevale :date',
        'scope_all' => 'kõik järgnevad',
        'scope_next' => 'järgmine',
    ],

    'toast' => [
        'created' => 'Stsenaarium „:name“ on loodud.',
        'deleted' => 'Stsenaarium on kustutatud.',
        'renamed' => 'Stsenaarium on ümber nimetatud.',
        'mutation_added' => 'Muudatus lisatud.',
        'mutation_updated' => 'Muudatus uuendatud.',
        'mutation_removed' => 'Muudatus eemaldatud.',
    ],

    'errors' => [
        'name_empty' => 'Stsenaariumi nimi ei saa olla tühi.',
        'name_too_long' => 'Stsenaariumi nimi tohib olla kuni :max märk.|Stsenaariumi nimi tohib olla kuni :max märki.',
        'name_taken' => 'Sellise nimega stsenaarium on juba olemas.',
        'date_out_of_range' => 'See kuupäev jääb väljapoole iga prognoosihorisonti — tänasest kuni :days päev ette —, nii et stsenaarium ei muudaks midagi.|See kuupäev jääb väljapoole iga prognoosihorisonti — tänasest kuni :days päeva ette —, nii et stsenaarium ei muudaks midagi.',
        'pick_kind_first' => 'Vali kõigepealt muudatuse liik.',
        'amount_positive' => 'Summa peab olema positiivne arv.',
        'scenario_gone' => 'Seda stsenaariumi enam ei ole — see kustutati mujal. Vali mõni teine stsenaarium või loo uus.',
        'mutation_gone' => 'Seda muudatust enam ei ole — see eemaldati mujal. Sulge redaktor ja lisa see uuesti, kui soovid seda veel.',
    ],
];
