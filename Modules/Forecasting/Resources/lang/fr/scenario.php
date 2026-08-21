<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Éditeur de scénario — :name',
    'rename_aria' => 'Renommer le scénario',
    'save' => 'Enregistrer',
    'save_changes' => 'Enregistrer les modifications',
    'cancel' => 'Annuler',
    'rename' => 'Renommer',
    'confirm_delete' => 'Confirmer la suppression',
    'delete_scenario' => 'Supprimer le scénario',
    'delete_confirm' => 'Supprimer ce scénario ?',

    'mutations_count' => 'Modifications (:count)',
    'no_mutations' => 'Pas encore de modifications. Ajoutes-en une ci-dessous pour voir comment ce scénario se compare à ta référence.',
    'editing' => 'Modification — :kind',
    'edit' => 'Modifier',
    'remove' => 'Supprimer',

    'add_mutation' => '+ Ajouter une modification',
    'add_to_scenario' => 'Ajouter au scénario',
    'pick_kind' => 'Choisis un type de modification :',

    'kind' => [
        'cancel_series' => [
            'title' => 'Annuler une série',
            'desc' => 'Retire toutes les occurrences prévues d\'une série approuvée.',
        ],
        'add_one_off' => [
            'title' => 'Ajouter un débit ou un crédit ponctuel',
            'desc' => 'Un seul événement hypothétique à une date précise.',
        ],
        'add_recurring' => [
            'title' => 'Ajouter une série récurrente',
            'desc' => 'Un nouvel abonnement ou revenu hypothétique.',
        ],
        'change_series_amount' => [
            'title' => 'Changer le montant d\'une série',
            'desc' => 'Simule une hausse ou une baisse de prix sur une série existante.',
        ],
        'shift_series_date' => [
            'title' => 'Décaler la date d\'une série',
            'desc' => 'Décale la prochaine occurrence ou toutes les suivantes.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Série à annuler',
        'pick_series' => '— choisis une série —',
        'date' => 'Date',
        'amount' => 'Montant',
        'currency' => 'Devise',
        'direction' => 'Sens',
        'expense_long' => 'Dépense (argent sortant)',
        'income_long' => 'Revenu (argent entrant)',
        'note' => 'Note (facultatif)',
        'start_date' => 'Date de début',
        'expense' => 'Dépense',
        'income' => 'Revenu',
        'cadence' => 'Fréquence',
        'cadence_weekly' => 'Hebdomadaire',
        'cadence_monthly' => 'Mensuelle',
        'cadence_quarterly' => 'Trimestrielle',
        'cadence_yearly' => 'Annuelle',
        'series' => 'Série',
        'new_amount' => 'Nouveau montant',
        'new_next_date' => 'Prochaine date',
        'scope' => 'Portée',
        'scope_legend' => 'Quelles occurrences décaler',
        'scope_next' => 'Seulement la prochaine occurrence',
        'scope_all' => 'Toutes les occurrences suivantes',
    ],

    'whatif' => [
        'trigger' => 'Simuler',
        'menu_aria' => 'Simuler pour :name',
        'model_cancellation' => 'Simuler une résiliation',
        'model_amount_change' => 'Simuler un changement de montant…',
        'amount_dialog_aria' => 'Simuler un changement de montant pour :name',
        'current_amount' => 'Montant actuel',
        'new_amount' => 'Nouveau montant',
    ],

    'series_name_fallback' => 'série',

    'summary' => [
        'cancel' => 'Annuler :name',
        'series_fallback' => 'série n° :id',
        'one_off' => ':amount :currency le :date',
        'recurring' => ':amount :currency :cadence à partir du :date',
        'change_amount' => ':name : nouveau montant :amount',
        'shift' => ':name : décaler :scope au :date',
        'scope_all' => 'toutes les suivantes',
        'scope_next' => 'la prochaine',
    ],

    'toast' => [
        'created' => 'Scénario « :name » créé.',
        'deleted' => 'Scénario supprimé.',
        'renamed' => 'Scénario renommé.',
        'mutation_added' => 'Modification ajoutée.',
        'mutation_updated' => 'Modification mise à jour.',
        'mutation_removed' => 'Modification supprimée. Annuler',
    ],

    'errors' => [
        'name_empty' => 'Le nom du scénario ne peut pas être vide.',
        'name_too_long' => 'Le nom du scénario ne doit pas dépasser :max caractère.|Le nom du scénario ne doit pas dépasser :max caractères.',
        'name_taken' => 'Un scénario portant ce nom existe déjà.',
        'pick_kind_first' => 'Choisis d\'abord un type de modification.',
        'amount_positive' => 'Le montant doit être un nombre positif.',
    ],
];
