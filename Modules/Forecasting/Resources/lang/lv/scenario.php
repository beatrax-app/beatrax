<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenāriju redaktors — :name',
    'rename_aria' => 'Pārdēvēt scenāriju',
    'save' => 'Saglabāt',
    'save_changes' => 'Saglabāt izmaiņas',
    'cancel' => 'Atcelt',
    'rename' => 'Pārdēvēt',
    'confirm_delete' => 'Apstiprināt dzēšanu',
    'delete_scenario' => 'Dzēst scenāriju',
    'delete_confirm' => 'Dzēst šo scenāriju?',

    'mutations_count' => 'Korekcijas (:count)',
    'no_mutations' => 'Vēl nav nevienas korekcijas. Pievienojiet vienu zemāk, lai redzētu, kā šis scenārijs izskatās salīdzinājumā ar bāzes līniju.',
    'editing' => 'Rediģē — :kind',
    'edit' => 'Rediģēt',
    'remove' => 'Noņemt',

    'add_mutation' => '+ Pievienot korekciju',
    'add_to_scenario' => 'Pievienot scenārijam',
    'pick_kind' => 'Izvēlieties korekcijas veidu:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Atcelt sēriju',
            'desc' => 'Izslēgt visus apstiprinātas sērijas prognozētos gadījumus.',
        ],
        'add_one_off' => [
            'title' => 'Pievienot vienreizēju maksājumu vai ieņēmumu',
            'desc' => 'Viens hipotētisks notikums konkrētā datumā.',
        ],
        'add_recurring' => [
            'title' => 'Pievienot regulāru sēriju',
            'desc' => 'Hipotētisks jauns abonements vai ieņēmumu plūsma.',
        ],
        'change_series_amount' => [
            'title' => 'Mainīt sērijas summu',
            'desc' => 'Modelēt cenas kāpumu vai kritumu esošai sērijai.',
        ],
        'shift_series_date' => [
            'title' => 'Pārbīdīt sērijas datumu',
            'desc' => 'Pārcelt nākamo vai visus turpmākos gadījumus uz priekšu.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Atceļamā sērija',
        'pick_series' => '— izvēlieties sēriju —',
        'date' => 'Datums',
        'amount' => 'Summa',
        'currency' => 'Valūta',
        'direction' => 'Virziens',
        'expense_long' => 'Izdevumi (nauda ārā)',
        'income_long' => 'Ieņēmumi (nauda iekšā)',
        'note' => 'Piezīme (neobligāti)',
        'start_date' => 'Sākuma datums',
        'expense' => 'Izdevumi',
        'income' => 'Ieņēmumi',
        'cadence' => 'Biežums',
        'cadence_weekly' => 'Reizi nedēļā',
        'cadence_monthly' => 'Reizi mēnesī',
        'cadence_quarterly' => 'Reizi ceturksnī',
        'cadence_yearly' => 'Reizi gadā',
        'series' => 'Sērija',
        'new_amount' => 'Jaunā summa',
        'new_next_date' => 'Jaunais nākamais datums',
        'scope' => 'Tvērums',
        'scope_legend' => 'Kuras reizes pārcelt',
        'scope_next' => 'Tikai nākamo reizi',
        'scope_all' => 'Visas turpmākās reizes',
    ],

    'whatif' => [
        'trigger' => 'Modelēt scenāriju',
        'menu_aria' => 'Modelēt scenāriju sērijai :name',
        'model_cancellation' => 'Modelēt atteikšanos',
        'model_amount_change' => 'Modelēt summas izmaiņas…',
        'amount_dialog_aria' => 'Modelēt summas izmaiņas sērijai :name',
        'current_amount' => 'Pašreizējā summa',
        'new_amount' => 'Jaunā summa',
    ],

    'summary' => [
        'cancel' => 'Atcelt :name',
        'series_fallback' => 'sērija #:id',
        'one_off' => ':amount :currency — :date',
        'recurring' => ':amount :currency :cadence no :date',
        'change_amount' => ':name: jaunā summa :amount',
        'shift' => ':name: pārbīdīt :scope uz :date',
        'scope_all' => 'visas turpmākās',
        'scope_next' => 'nākamo',
    ],

    'toast' => [
        'created' => 'Scenārijs „:name” izveidots.',
        'deleted' => 'Scenārijs dzēsts.',
        'renamed' => 'Scenārijs pārdēvēts.',
        'mutation_added' => 'Korekcija pievienota.',
        'mutation_updated' => 'Korekcija atjaunināta.',
        'mutation_removed' => 'Korekcija noņemta. Atsaukt',
    ],

    'errors' => [
        'name_empty' => 'Scenārija nosaukums nevar būt tukšs.',
        'name_too_long' => 'Scenārija nosaukumā jābūt ne vairāk kā :max rakstzīmēm.',
        'name_taken' => 'Scenārijs ar šādu nosaukumu jau pastāv.',
        'pick_kind_first' => 'Vispirms izvēlieties korekcijas veidu.',
        'amount_positive' => 'Summai jābūt pozitīvam skaitlim.',
    ],
];
