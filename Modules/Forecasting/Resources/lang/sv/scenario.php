<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenarioredigerare — :name',
    'rename_aria' => 'Byt namn på scenariot',
    'save' => 'Spara',
    'save_changes' => 'Spara ändringar',
    'cancel' => 'Avbryt',
    'rename' => 'Byt namn',
    'confirm_delete' => 'Bekräfta borttagning',
    'delete_scenario' => 'Ta bort scenario',
    'delete_confirm' => 'Ta bort det här scenariot?',

    'mutations_count' => 'Ändringar (:count)',
    'no_mutations' => 'Inga ändringar än. Lägg till en nedan för att se hur det här scenariot står sig mot din baslinje.',
    'editing' => 'Redigerar — :kind',
    'edit' => 'Redigera',
    'remove' => 'Ta bort',

    'add_mutation' => '+ Lägg till ändring',
    'add_to_scenario' => 'Lägg till i scenariot',
    'pick_kind' => 'Välj typ av ändring:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Säg upp en serie',
            'desc' => 'Ta bort alla prognostiserade händelser i en godkänd serie.',
        ],
        'add_one_off' => [
            'title' => 'Lägg till en engångsutgift eller engångsinkomst',
            'desc' => 'En enskild hypotetisk händelse på ett visst datum.',
        ],
        'add_recurring' => [
            'title' => 'Lägg till en återkommande serie',
            'desc' => 'En hypotetisk ny prenumeration eller inkomstkälla.',
        ],
        'change_series_amount' => [
            'title' => 'Ändra beloppet i en serie',
            'desc' => 'Simulera en prishöjning eller prissänkning i en befintlig serie.',
        ],
        'shift_series_date' => [
            'title' => 'Flytta datumet i en serie',
            'desc' => 'Flytta fram nästa händelse eller alla efterföljande händelser.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serie att säga upp',
        'pick_series' => '— välj en serie —',
        'date' => 'Datum',
        'amount' => 'Belopp',
        'currency' => 'Valuta',
        'direction' => 'Riktning',
        'expense_long' => 'Utgift (pengar ut)',
        'income_long' => 'Inkomst (pengar in)',
        'note' => 'Anteckning (valfritt)',
        'start_date' => 'Startdatum',
        'expense' => 'Utgift',
        'income' => 'Inkomst',
        'cadence' => 'Intervall',
        'cadence_weekly' => 'Varje vecka',
        'cadence_monthly' => 'Varje månad',
        'cadence_quarterly' => 'Varje kvartal',
        'cadence_yearly' => 'Varje år',
        'series' => 'Serie',
        'new_amount' => 'Nytt belopp',
        'new_next_date' => 'Nytt nästa datum',
        'scope' => 'Omfattning',
        'scope_legend' => 'Vilka händelser som ska flyttas',
        'scope_next' => 'Bara nästa händelse',
        'scope_all' => 'Alla efterföljande händelser',
    ],

    'whatif' => [
        'trigger' => 'Testa scenario',
        'menu_aria' => 'Testa scenario för :name',
        'model_cancellation' => 'Simulera uppsägning',
        'model_amount_change' => 'Simulera beloppsändring…',
        'amount_dialog_aria' => 'Simulera beloppsändring för :name',
        'current_amount' => 'Nuvarande belopp',
        'new_amount' => 'Nytt belopp',
    ],

    'series_name_fallback' => 'serie',

    'summary' => [
        'cancel' => 'Säg upp :name',
        'series_fallback' => 'serie #:id',
        'one_off' => ':amount :currency den :date',
        'recurring' => ':amount :currency :cadence från :date',
        'change_amount' => ':name: nytt belopp :amount',
        'shift' => ':name: flytta :scope till :date',
        'scope_all' => 'alla efterföljande',
        'scope_next' => 'nästa',
    ],

    'toast' => [
        'created' => 'Scenariot ":name" har skapats.',
        'deleted' => 'Scenariot har tagits bort.',
        'renamed' => 'Scenariot har bytt namn.',
        'mutation_added' => 'Ändringen har lagts till.',
        'mutation_updated' => 'Ändringen har uppdaterats.',
        'mutation_removed' => 'Ändringen har tagits bort. Ångra',
    ],

    'errors' => [
        'name_empty' => 'Scenarionamnet får inte vara tomt.',
        'name_too_long' => 'Scenarionamnet får vara högst :max tecken.|Scenarionamnet får vara högst :max tecken.',
        'name_taken' => 'Det finns redan ett scenario med det namnet.',
        'pick_kind_first' => 'Välj först en typ av ändring.',
        'amount_positive' => 'Beloppet måste vara ett positivt tal.',
    ],
];
