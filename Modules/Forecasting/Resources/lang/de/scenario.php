<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Szenario-Editor — :name',
    'rename_aria' => 'Szenario umbenennen',
    'save' => 'Speichern',
    'save_changes' => 'Änderungen speichern',
    'cancel' => 'Abbrechen',
    'rename' => 'Umbenennen',
    'confirm_delete' => 'Löschen bestätigen',
    'delete_scenario' => 'Szenario löschen',
    'delete_confirm' => 'Dieses Szenario löschen?',

    'mutations_count' => 'Anpassungen (:count)',
    'no_mutations' => 'Noch keine Anpassungen. Füge unten eine hinzu, um zu sehen, wie sich dieses Szenario zu deiner Basislinie verhält.',
    'editing' => 'Bearbeiten — :kind',
    'edit' => 'Bearbeiten',
    'remove' => 'Entfernen',

    'add_mutation' => '+ Anpassung hinzufügen',
    'add_to_scenario' => 'Zum Szenario hinzufügen',
    'pick_kind' => 'Wähle eine Art von Anpassung:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Eine Reihe kündigen',
            'desc' => 'Lässt jedes prognostizierte Vorkommen einer bestätigten Reihe entfallen.',
        ],
        'add_one_off' => [
            'title' => 'Einmalige Abbuchung oder Gutschrift hinzufügen',
            'desc' => 'Ein einzelnes hypothetisches Ereignis an einem bestimmten Datum.',
        ],
        'add_recurring' => [
            'title' => 'Wiederkehrende Reihe hinzufügen',
            'desc' => 'Ein hypothetisches neues Abo oder eine neue Einnahmequelle.',
        ],
        'change_series_amount' => [
            'title' => 'Betrag einer Reihe ändern',
            'desc' => 'Simuliere eine Preiserhöhung oder -senkung bei einer bestehenden Reihe.',
        ],
        'shift_series_date' => [
            'title' => 'Datum einer Reihe verschieben',
            'desc' => 'Verschiebe das nächste oder alle folgenden Vorkommen nach vorn.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Zu kündigende Reihe',
        'pick_series' => '— Reihe wählen —',
        'date' => 'Datum',
        'amount' => 'Betrag',
        'currency' => 'Währung',
        'direction' => 'Richtung',
        'expense_long' => 'Ausgabe (Geld raus)',
        'income_long' => 'Einnahme (Geld rein)',
        'note' => 'Notiz (optional)',
        'start_date' => 'Startdatum',
        'expense' => 'Ausgabe',
        'income' => 'Einnahme',
        'cadence' => 'Rhythmus',
        'cadence_weekly' => 'Wöchentlich',
        'cadence_monthly' => 'Monatlich',
        'cadence_quarterly' => 'Vierteljährlich',
        'cadence_yearly' => 'Jährlich',
        'series' => 'Reihe',
        'new_amount' => 'Neuer Betrag',
        'new_next_date' => 'Neues nächstes Datum',
        'scope' => 'Umfang',
        'scope_legend' => 'Welche Vorkommen verschoben werden',
        'scope_next' => 'Nur das nächste Vorkommen',
        'scope_all' => 'Alle folgenden Vorkommen',
    ],

    'whatif' => [
        'trigger' => 'Was-wäre-wenn',
        'menu_aria' => 'Was-wäre-wenn für :name simulieren',
        'model_cancellation' => 'Kündigung simulieren',
        'model_amount_change' => 'Betragsänderung simulieren…',
        'amount_dialog_aria' => 'Betragsänderung für :name simulieren',
        'current_amount' => 'Aktueller Betrag',
        'new_amount' => 'Neuer Betrag',
    ],

    'series_name_fallback' => 'Reihe',

    'summary' => [
        'cancel' => ':name kündigen',
        'series_fallback' => 'Reihe #:id',
        'one_off' => ':amount :currency am :date',
        'recurring' => ':amount :currency :cadence ab :date',
        'change_amount' => ':name: neuer Betrag :amount',
        'shift' => ':name: :scope auf :date verschieben',
        'scope_all' => 'alle folgenden',
        'scope_next' => 'nächstes',
    ],

    'toast' => [
        'created' => 'Szenario „:name“ angelegt.',
        'deleted' => 'Szenario gelöscht.',
        'renamed' => 'Szenario umbenannt.',
        'mutation_added' => 'Anpassung hinzugefügt.',
        'mutation_updated' => 'Anpassung aktualisiert.',
        'mutation_removed' => 'Anpassung entfernt. Rückgängig',
    ],

    'errors' => [
        'name_empty' => 'Der Szenarioname darf nicht leer sein.',
        'name_too_long' => 'Der Szenarioname darf höchstens :max Zeichen lang sein.',
        'name_taken' => 'Ein Szenario mit diesem Namen gibt es bereits.',
        'pick_kind_first' => 'Wähle zuerst eine Art von Anpassung.',
        'amount_positive' => 'Der Betrag muss eine positive Zahl sein.',
    ],
];
