<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Objectif : :name',
        'category_goal' => 'Objectif de la catégorie :name',
        'schedule_untitled' => 'Transaction planifiée sans nom',
        'transaction' => 'Transaction : :name · :date · :amount',
        'transaction_unnamed' => 'Transaction',
        'amount_update' => 'Mise à jour du montant de la transaction',
        'budget_history' => 'Historique de budget en :currency',
        'budget_file_currency' => 'Devise du fichier de budget',
        'budget_file_mode' => 'Mode du fichier de budget',
    ],

    'conflict' => [
        'budget_assignment' => 'Affectation budgétaire',
        'budget_for_month' => 'Budget de :category · :month',
        'budget_for_category' => 'Budget de :category',
        'category_name' => 'Nom de catégorie',
        'category_name_of' => 'Nom de la catégorie « :name »',
        'account_name' => 'Nom de compte',
        'account_name_of' => 'Nom du compte « :name »',
        'transaction_amount' => 'Montant de la transaction',
        'transaction_amount_of' => 'Montant : :name',
        'transaction_amount_of_dated' => 'Montant : :name · :date',
        'transaction_description' => 'Description de la transaction',
        'transaction_description_of' => 'Description : :name',
        'transaction_description_of_dated' => 'Description : :name · :date',
        'other' => 'Valeur importée',
    ],

    'reason' => [
        'fingerprint_collision' => "Cette transaction est entrée en collision avec une autre transaction déjà enregistrée (empreinte identique) et n'a pas été importée.",
        'split_legs_without_category' => ":count ligne de la ventilation sur :legs n'a pas de catégorie, et une ligne ne peut pas être enregistrée sans. La transaction a été importée pour son montant complet et attend dans la catégorie :uncategorized.|:count lignes de la ventilation sur :legs n'ont pas de catégorie, et une ligne ne peut pas être enregistrée sans. La transaction a été importée pour son montant complet et attend dans la catégorie :uncategorized.",
        'split_sum_mismatch' => 'Les lignes de la ventilation totalisent :legs alors que la transaction est à :total, et une ventilation doit correspondre exactement à sa transaction. La transaction a été importée pour son montant complet, sans ses lignes.',
        'split_unstorable' => 'Beatrax ne peut pas enregistrer cette ventilation telle quelle, la transaction a donc été importée seule, sans ses lignes.',
        'goal_without_target_date' => "Cet objectif n'a pas de date cible ; Beatrax en exige une pour créer un objectif d'épargne.",
        'goal_without_name' => "Cet objectif n'a pas de nom ; Beatrax en exige un pour créer un objectif d'épargne.",
        'goal_def_unsupported' => "categories.goal_def utilise une forme de modèle non prise en charge (non plate) — l'objectif n'a pas été importé.",
        'budget_currency_mismatch' => ":count ligne de budget n'a pas été importée : tes budgets sont tenus en :envelope, et cet export budgète en :source.|:count lignes de budget n'ont pas été importées : tes budgets sont tenus en :envelope, et cet export budgète en :source.",
        'amount_apply_collision' => "Le nouveau montant de la source n'a pas pu être appliqué — il entre en collision avec l'empreinte d'une autre transaction (même compte, même date, même devise et même tiers). Laissé inchangé.",
        'schedule_unsupported' => "Les transactions planifiées et récurrentes n'ont pas encore de voie de création depuis une source externe dans Beatrax — elles ne sont conservées que sous forme de note, pas comme une série récurrente active.",
        'saved_report_unsupported' => "Les rapports enregistrés et les configurations d'analyse n'ont pas d'équivalent dans Beatrax.",
        'assumed_currency' => "Devise supposée : :currency — aucune ligne 'preferences.currencyCode' n'a été trouvée dans cet export.",
        'assumed_budget_type' => "Mode supposé : :mode — aucune ligne 'preferences.budgetType' n'a été trouvée dans cet export.",
        'changed_on_both_sides' => "Le fichier source et Beatrax ont tous les deux modifié cela depuis le dernier import.\nLocal : :local\nSource : :source\nDernier import : :baseline",
        'take_source' => 'La valeur du nouvel export sera appliquée quand tu confirmeras — ta valeur locale sera remplacée.',
        'keep_local' => 'Ta valeur locale sera conservée — la valeur du nouvel export ne sera pas appliquée.',
        'compared_values' => ":intro\nLocal : :local · Source : :source · Dernier import : :baseline",
    ],

    'value' => [
        'none' => '(aucune)',
        'quoted' => '« :value »',
    ],
];
