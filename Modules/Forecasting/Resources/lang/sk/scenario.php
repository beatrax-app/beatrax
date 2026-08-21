<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor scenára — :name',
    'rename_aria' => 'Premenovať scenár',
    'save' => 'Uložiť',
    'save_changes' => 'Uložiť zmeny',
    'cancel' => 'Zrušiť',
    'rename' => 'Premenovať',
    'confirm_delete' => 'Potvrdiť odstránenie',
    'delete_scenario' => 'Odstrániť scenár',
    'delete_confirm' => 'Odstrániť tento scenár?',

    'mutations_count' => 'Zmeny (:count)',
    'no_mutations' => 'Zatiaľ žiadne zmeny. Pridaj nižšie prvú a uvidíš, ako tento scenár vychádza oproti východisku.',
    'editing' => 'Úprava — :kind',
    'edit' => 'Upraviť',
    'remove' => 'Odstrániť',

    'add_mutation' => '+ Pridať zmenu',
    'add_to_scenario' => 'Pridať do scenára',
    'pick_kind' => 'Vyber druh zmeny:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Zrušiť sériu',
            'desc' => 'Vynechá každý predpokladaný výskyt schválenej série.',
        ],
        'add_one_off' => [
            'title' => 'Pridať jednorazovú platbu alebo príjem',
            'desc' => 'Jedna hypotetická udalosť v konkrétny deň.',
        ],
        'add_recurring' => [
            'title' => 'Pridať opakovanú sériu',
            'desc' => 'Hypotetické nové predplatné alebo zdroj príjmu.',
        ],
        'change_series_amount' => [
            'title' => 'Zmeniť sumu série',
            'desc' => 'Namodeluj zdraženie alebo zlacnenie existujúcej série.',
        ],
        'shift_series_date' => [
            'title' => 'Posunúť dátum série',
            'desc' => 'Posunie najbližší alebo všetky nasledujúce výskyty.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Séria na zrušenie',
        'pick_series' => '— vyber sériu —',
        'date' => 'Dátum',
        'amount' => 'Suma',
        'currency' => 'Mena',
        'direction' => 'Smer',
        'expense_long' => 'Výdavok (peniaze von)',
        'income_long' => 'Príjem (peniaze dnu)',
        'note' => 'Poznámka (nepovinné)',
        'start_date' => 'Dátum začiatku',
        'expense' => 'Výdavok',
        'income' => 'Príjem',
        'cadence' => 'Frekvencia',
        'cadence_weekly' => 'Týždenne',
        'cadence_monthly' => 'Mesačne',
        'cadence_quarterly' => 'Štvrťročne',
        'cadence_yearly' => 'Ročne',
        'series' => 'Séria',
        'new_amount' => 'Nová suma',
        'new_next_date' => 'Nový ďalší dátum',
        'scope' => 'Rozsah',
        'scope_legend' => 'Ktoré výskyty posunúť',
        'scope_next' => 'Len najbližší výskyt',
        'scope_all' => 'Všetky nasledujúce výskyty',
    ],

    'whatif' => [
        'trigger' => 'Namodelovať čo ak',
        'menu_aria' => 'Namodelovať čo ak pre: :name',
        'model_cancellation' => 'Namodelovať zrušenie',
        'model_amount_change' => 'Namodelovať zmenu sumy…',
        'amount_dialog_aria' => 'Namodelovať zmenu sumy pre: :name',
        'current_amount' => 'Aktuálna suma',
        'new_amount' => 'Nová suma',
    ],

    'series_name_fallback' => 'séria',

    'summary' => [
        'cancel' => 'Zrušiť: :name',
        'series_fallback' => 'séria č. :id',
        'one_off' => ':amount :currency dňa :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: nová suma :amount',
        'shift' => ':name: posun :scope na :date',
        'scope_all' => 'všetky nasledujúce',
        'scope_next' => 'najbližší',
    ],

    'toast' => [
        'created' => 'Scenár „:name“ bol vytvorený.',
        'deleted' => 'Scenár odstránený.',
        'renamed' => 'Scenár premenovaný.',
        'mutation_added' => 'Zmena pridaná.',
        'mutation_updated' => 'Zmena upravená.',
        'mutation_removed' => 'Zmena odstránená. Späť',
    ],

    'errors' => [
        'name_empty' => 'Názov scenára nemôže byť prázdny.',
        'name_too_long' => 'Názov scenára môže mať najviac :max znak.|Názov scenára môže mať najviac :max znaky.|Názov scenára môže mať najviac :max znakov.',
        'name_taken' => 'Scenár s takým názvom už existuje.',
        'pick_kind_first' => 'Najprv vyber druh zmeny.',
        'amount_positive' => 'Suma musí byť kladné číslo.',
    ],
];
