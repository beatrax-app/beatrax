<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Ziel: :name',
        'category_goal' => 'Ziel der Kategorie :name',
        'schedule_untitled' => 'Geplante Transaktion ohne Namen',
        'transaction' => 'Transaktion: :name · :date · :amount',
        'transaction_unnamed' => 'Transaktion',
        'amount_update' => 'Aktualisierung des Transaktionsbetrags',
        'budget_history' => 'Budgethistorie in :currency',
        'budget_file_currency' => 'Währung der Budgetdatei',
        'budget_file_mode' => 'Modus der Budgetdatei',
    ],

    'conflict' => [
        'budget_assignment' => 'Budgetzuweisung',
        'budget_for_month' => 'Budget für :category · :month',
        'budget_for_category' => 'Budget für :category',
        'category_name' => 'Kategoriename',
        'category_name_of' => 'Kategoriename von „:name“',
        'account_name' => 'Kontoname',
        'account_name_of' => 'Kontoname von „:name“',
        'transaction_amount' => 'Transaktionsbetrag',
        'transaction_amount_of' => 'Betrag: :name',
        'transaction_amount_of_dated' => 'Betrag: :name · :date',
        'transaction_description' => 'Transaktionsbeschreibung',
        'transaction_description_of' => 'Beschreibung: :name',
        'transaction_description_of_dated' => 'Beschreibung: :name · :date',
        'other' => 'Importierter Wert',
    ],

    'reason' => [
        'fingerprint_collision' => 'Diese Transaktion kollidierte mit einer bereits erfassten Transaktion (identischer Fingerabdruck) und wurde nicht importiert.',
        'reconciled_status_kept' => 'Der Abgleichstatus der Quelle ließ sich nicht anwenden — diese Transaktion ist in Beatrax abgeglichen, und nur das Aufheben des Abgleichs ändert das. Unverändert gelassen.',
        'split_legs_without_category' => ':count Aufteilungsposition von :legs hat keine Kategorie, und ohne Kategorie lässt sich eine Position nicht speichern. Die Transaktion wurde mit ihrem vollen Betrag importiert und wartet in der Kategorie :uncategorized.|:count Aufteilungspositionen von :legs haben keine Kategorie, und ohne Kategorie lässt sich eine Position nicht speichern. Die Transaktion wurde mit ihrem vollen Betrag importiert und wartet in der Kategorie :uncategorized.',
        'split_sum_mismatch' => 'Die Positionen der Aufteilung ergeben zusammen :legs, die Transaktion beträgt aber :total, und eine Aufteilung muss ihrer Transaktion genau entsprechen. Die Transaktion wurde mit ihrem vollen Betrag importiert, ohne ihre Positionen.',
        'split_unstorable' => 'Beatrax kann diese Aufteilung so nicht speichern, deshalb wurde die Transaktion allein importiert, ohne ihre Positionen.',
        'goal_without_target_date' => 'Dieses Ziel hat kein Zieldatum; Beatrax braucht eines, um ein Sparziel anzulegen.',
        'goal_without_name' => 'Dieses Ziel hat keinen Namen; Beatrax braucht einen, um ein Sparziel anzulegen.',
        'goal_def_unsupported' => 'categories.goal_def verwendet eine nicht unterstützte (nicht flache) Vorlagenform — das Ziel wurde nicht importiert.',
        'budget_currency_mismatch' => ':count Budgetzeile wurde nicht importiert: deine Budgets werden in :envelope geführt, und dieser Export budgetiert in :source.|:count Budgetzeilen wurden nicht importiert: deine Budgets werden in :envelope geführt, und dieser Export budgetiert in :source.',
        'amount_apply_collision' => 'Der neue Betrag der Quelle ließ sich nicht anwenden — er kollidiert mit dem Fingerabdruck einer anderen Transaktion (gleiches Konto, gleiches Datum, gleiche Währung und gleicher Zahlungspartner). Unverändert gelassen.',
        'amount_currency_mismatch' => 'Transaktionsbeträge wurden nicht abgeglichen: diese Transaktionen werden in :local geführt, dieser Export gibt sie aber in :source an. Unverändert gelassen.',
        'schedule_unsupported' => 'Geplante und wiederkehrende Transaktionen haben in Beatrax noch keinen Weg, aus einer externen Quelle angelegt zu werden — sie sind nur als Notiz erhalten, nicht als aktive wiederkehrende Serie.',
        'saved_report_unsupported' => 'Gespeicherte Berichte und Analysekonfigurationen haben in Beatrax keine Entsprechung.',
        'assumed_currency' => "Angenommen: :currency — in diesem Export wurde keine Zeile 'preferences.currencyCode' gefunden.",
        'assumed_budget_type' => "Angenommen: :mode — in diesem Export wurde keine Zeile 'preferences.budgetType' gefunden.",
        'changed_on_both_sides' => "Sowohl die Quelldatei als auch Beatrax haben das seit dem letzten Import geändert.\nLokal: :local\nQuelle: :source\nZuletzt importiert: :baseline",
        'take_source' => 'Der Wert des neuen Exports wird angewendet, sobald du bestätigst — dein lokaler Wert wird ersetzt.',
        'keep_local' => 'Dein lokaler Wert bleibt erhalten — der Wert des neuen Exports wird nicht angewendet.',
        'compared_values' => ":intro\nLokal: :local · Quelle: :source · Zuletzt importiert: :baseline",
    ],

    'value' => [
        'none' => '(keiner)',
        'quoted' => '„:value“',
    ],
];
