<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor scénáře — :name',
    'rename_aria' => 'Přejmenovat scénář',
    'save' => 'Uložit',
    'save_changes' => 'Uložit změny',
    'cancel' => 'Zrušit',
    'rename' => 'Přejmenovat',
    'confirm_delete' => 'Potvrdit smazání',
    'delete_scenario' => 'Smazat scénář',
    'delete_confirm' => 'Smazat tento scénář?',

    'mutations_count' => 'Změny (:count)',
    'no_mutations' => 'Zatím žádné změny. Přidej jednu níž a uvidíš, jak si tenhle scénář stojí proti základu.',
    'editing' => 'Úprava — :kind',
    'edit' => 'Upravit',
    'remove' => 'Odebrat',

    'add_mutation' => '+ Přidat změnu',
    'add_to_scenario' => 'Přidat do scénáře',
    'pick_kind' => 'Vyber druh změny:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Zrušit řadu',
            'desc' => 'Vynechat každý předpokládaný výskyt schválené řady.',
        ],
        'add_one_off' => [
            'title' => 'Přidat jednorázovou platbu nebo příjem',
            'desc' => 'Jedna hypotetická událost ke konkrétnímu datu.',
        ],
        'add_recurring' => [
            'title' => 'Přidat opakovanou řadu',
            'desc' => 'Hypotetické nové předplatné nebo zdroj příjmu.',
        ],
        'change_series_amount' => [
            'title' => 'Změnit částku řady',
            'desc' => 'Namodeluj zdražení nebo zlevnění existující řady.',
        ],
        'shift_series_date' => [
            'title' => 'Posunout datum řady',
            'desc' => 'Posunout dopředu příští nebo všechny další výskyty.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Řada ke zrušení',
        'pick_series' => '— vyber řadu —',
        'date' => 'Datum',
        'amount' => 'Částka',
        'currency' => 'Měna',
        'direction' => 'Směr',
        'expense_long' => 'Výdaj (peníze ven)',
        'income_long' => 'Příjem (peníze dovnitř)',
        'note' => 'Poznámka (nepovinné)',
        'start_date' => 'Datum začátku',
        'expense' => 'Výdaj',
        'income' => 'Příjem',
        'cadence' => 'Frekvence',
        'cadence_weekly' => 'Týdně',
        'cadence_monthly' => 'Měsíčně',
        'cadence_quarterly' => 'Čtvrtletně',
        'cadence_yearly' => 'Ročně',
        'series' => 'Řada',
        'new_amount' => 'Nová částka',
        'new_next_date' => 'Nové datum příštího výskytu',
        'scope' => 'Rozsah',
        'scope_legend' => 'Které výskyty posunout',
        'scope_next' => 'Jen příští výskyt',
        'scope_all' => 'Všechny další výskyty',
    ],

    'whatif' => [
        'trigger' => 'Namodelovat variantu',
        'menu_aria' => 'Namodelovat variantu pro: :name',
        'model_cancellation' => 'Namodelovat zrušení',
        'model_amount_change' => 'Namodelovat změnu částky…',
        'amount_dialog_aria' => 'Namodelovat změnu částky pro: :name',
        'current_amount' => 'Současná částka',
        'new_amount' => 'Nová částka',
    ],

    'series_name_fallback' => 'řada',

    'summary' => [
        'cancel' => 'Zrušení: :name',
        'series_fallback' => 'řada #:id',
        'one_off' => ':amount :currency dne :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: nová částka :amount',
        'shift' => ':name: posun :scope na :date',
        'scope_all' => 'všech dalších',
        'scope_next' => 'příštího',
    ],

    'toast' => [
        'created' => 'Scénář „:name“ vytvořen.',
        'deleted' => 'Scénář smazán.',
        'renamed' => 'Scénář přejmenován.',
        'mutation_added' => 'Změna přidána.',
        'mutation_updated' => 'Změna upravena.',
        'mutation_removed' => 'Změna odebrána. Vrátit zpět',
    ],

    'errors' => [
        'name_empty' => 'Název scénáře nemůže být prázdný.',
        'name_too_long' => 'Název scénáře může mít nejvýš :max znaků.',
        'name_taken' => 'Scénář s tímto názvem už existuje.',
        'pick_kind_first' => 'Nejdřív vyber druh změny.',
        'amount_positive' => 'Částka musí být kladné číslo.',
    ],
];
