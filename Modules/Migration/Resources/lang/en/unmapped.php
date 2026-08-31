<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Goal: :name',
        'category_goal' => ':name goal',
        'schedule_untitled' => 'Untitled schedule',
        'transaction' => 'Transaction: :name · :date · :amount',
        'transaction_unnamed' => 'Transaction',
        'amount_update' => 'Transaction amount update',
        'budget_history' => 'Budget history in :currency',
        'budget_file_currency' => 'Budget-file currency',
        'budget_file_mode' => 'Budget-file mode',
    ],

    'conflict' => [
        'budget_assignment' => 'Budget assignment',
        'budget_for_month' => ':category · :month budget',
        'budget_for_category' => ':category budget',
        'category_name' => 'Category name',
        'category_name_of' => '":name" category name',
        'account_name' => 'Account name',
        'account_name_of' => '":name" account name',
        'transaction_amount' => 'Transaction amount',
        'transaction_amount_of' => ':name amount',
        'transaction_amount_of_dated' => ':name · :date amount',
        'transaction_description' => 'Transaction description',
        'transaction_description_of' => ':name description',
        'transaction_description_of_dated' => ':name · :date description',
        'other' => 'Imported value',
    ],

    'reason' => [
        'fingerprint_collision' => 'This transaction collided with another already-recorded transaction (identical fingerprint) and was not imported.',
        'split_legs_without_category' => ':count split leg of :legs carries no category, and a split leg cannot be stored without one. The transaction was imported at its full amount and is waiting in :uncategorized.|:count split legs of :legs carry no category, and a split leg cannot be stored without one. The transaction was imported at its full amount and is waiting in :uncategorized.',
        'split_sum_mismatch' => 'The split legs add up to :legs but the transaction is :total, and a split has to match its transaction exactly. The transaction was imported at its full amount, without its legs.',
        'split_unstorable' => 'Beatrax cannot store this split as it stands, so the transaction was imported on its own, without its legs.',
        'goal_without_target_date' => 'This goal has no target date; Beatrax requires one to create a savings goal.',
        'goal_without_name' => 'This goal has no name; Beatrax requires one to create a savings goal.',
        'goal_def_unsupported' => 'categories.goal_def uses an unsupported (non-flat) template shape — the goal was not imported.',
        'budget_currency_mismatch' => ':count budget row was not imported: your budgets are kept in :envelope, and this export budgets in :source.|:count budget rows were not imported: your budgets are kept in :envelope, and this export budgets in :source.',
        'amount_apply_collision' => "The source's new amount could not be applied — it collides with another transaction's fingerprint (same account, date, currency and counterparty). Left unchanged.",
        'schedule_unsupported' => 'Scheduled and recurring transactions have no Beatrax create-from-external-source path yet — preserved as a note only, not a live Recurring series.',
        'saved_report_unsupported' => 'Saved reports and analysis configs have no Beatrax equivalent.',
        'assumed_currency' => "Assumed :currency — no 'preferences.currencyCode' row was found in this export.",
        'assumed_budget_type' => "Assumed :mode — no 'preferences.budgetType' row was found in this export.",
        'changed_on_both_sides' => "Both the source file and Beatrax changed this since the last import.\nLocal: :local\nSource: :source\nLast imported: :baseline",
        'take_source' => "The new export's value will be applied when you confirm — your local value will be replaced.",
        'keep_local' => "Your local value will be kept — the new export's value will not be applied.",
        'compared_values' => ":intro\nLocal: :local · Source: :source · Last imported: :baseline",
    ],

    'value' => [
        'none' => '(none)',
        'quoted' => '":value"',
    ],
];
