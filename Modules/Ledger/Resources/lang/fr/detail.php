<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaction',
    'heading' => 'Transaction',

    'counterparty' => 'Tiers',
    'amount_native' => 'Montant (devise d\'origine)',
    'amount_settled' => 'Montant (réglé en EUR)',
    'effective_rate' => 'Taux effectif',
    'ics_markup' => 'Inclut la majoration ICS éventuelle.',

    'split' => [
        'category' => 'Catégorie',
        'open' => 'Ventiler par catégories',
        'heading' => 'Ventiler sur plusieurs catégories',
        'total' => 'Total :amount',
        'tax_per_category' => 'Le marquage fiscal se règle par catégorie ci-dessous.',
        'choose_category' => 'Choisis une catégorie',
        'note_label' => 'Note',
        'note_placeholder' => 'Note (facultatif)',
        'tax_deductible' => 'Déductible des impôts',
        'remove_leg_aria' => 'Supprimer cette catégorie',
        'add_category' => '+ Ajouter une catégorie',
        'soft_cap' => ':count sur ~20 catégories — pense à regrouper les petits montants.',
        'remaining_zero' => 'Restant :amount ✓',
        'remaining_to_assign' => 'Reste à répartir : :amount',
        'over_allocated' => 'Dépassement de :amount — réduis une ligne.',
        'save' => 'Enregistrer la ventilation',
        'saving' => 'Enregistrement…',
        'unsplit' => 'Annuler la ventilation',
        'remove_to_one' => 'Si tu supprimes ceci, il ne reste qu\'une catégorie — la transaction devient :category.',
        'remove_to_one_fallback' => 'cette catégorie',
        'remove_category' => 'Supprimer la catégorie',
        'keep_category' => 'Garder cette catégorie',
        'restore_single' => 'Rétablir en une seule catégorie ?',
        'confirm_unsplit' => 'Oui, annuler la ventilation',
        'keep_split' => 'Garder la ventilation',
    ],

    'tax' => [
        'section_aria' => 'Marquage fiscal',
        'label' => 'Déductible des impôts',
    ],

    'reclassify' => [
        'heading' => 'Reclasser',
        'help' => 'Remplace le type détecté. Si cette transaction est appariée à une autre, choisir un type autre que virement dissociera les deux côtés.',
        'choose_aria' => 'Choisir un nouveau type de transaction',
        'choose_option' => 'Choisis un type…',
        'save' => 'Enregistrer',
    ],

    'note' => [
        'heading' => 'Note',
        'help' => 'Note personnelle pour cette transaction. Visible uniquement par toi.',
        'label' => 'Note',
        'placeholder' => 'Ajoute une note…',
        'save' => 'Enregistrer la note',
        'saved' => 'Enregistrée',
    ],

    'reassign' => [
        'heading' => 'Réattribuer le tiers',
        'help' => 'Remplace le tiers détecté pour cette transaction.',
        'choose_aria' => 'Choisir un tiers',
        'choose_option' => 'Choisis un tiers…',
        'submit' => 'Réattribuer',
    ],

    'goal' => [
        'heading' => "Objectif d'épargne",
        'help' => "Comptabiliser cette transaction dans l'un de tes objectifs d'épargne.",
        'choose_aria' => "Choisir un objectif d'épargne",
        'choose_option' => 'Choisir un objectif…',
        'submit' => "Ajouter à l'objectif",
        'remove_aria' => 'Retirer :name',
    ],

    'delete' => [
        'heading' => 'Supprimer la transaction',
        'help' => 'Supprime définitivement cette transaction. Cette action est irréversible.',
        'button' => 'Supprimer',
        'confirm_prompt' => 'Tu es sûr ?',
        'confirm' => 'Oui, supprimer',
        'cancel' => 'Annuler',
    ],

    'chain' => [
        'view' => 'Voir la chaîne',
    ],

    'toast' => [
        'reconciled_locked' => 'Cette transaction est rapprochée. Annule le rapprochement pour la modifier.',
        'reclassified_pair_removed' => 'Reclassée en :type — appariement supprimé',
        'reclassified' => 'Reclassée en :type',
        'note_saved' => 'Note enregistrée',
        'unreconciled' => 'Rapprochement annulé — tu peux de nouveau modifier cette transaction.',
        'counterparty_updated' => 'Tiers mis à jour',
        'goal_attributed' => 'Comptabilisé dans cet objectif',
        'goal_attribution_removed' => "N'est plus comptabilisé dans cet objectif",
        'split_saved' => 'Ventilation enregistrée',
        'removed_one_remains' => 'Supprimée — une catégorie reste',
        'unsplit_restored' => 'Ventilation annulée — rétablie en une seule catégorie',
    ],

    'errors' => [
        'totals_must_match' => 'Enregistrement impossible — le total des lignes doit correspondre exactement au total de la transaction.',
        'not_found' => 'Transaction introuvable.',
        'amount_zero' => 'Le montant ne peut pas être 0,00 €',
        'choose_category' => 'Choisis une catégorie.',
        'choose_before_removing' => 'Choisis une catégorie avant de supprimer.',
        'choose_before_unsplitting' => 'Choisis une catégorie avant d\'annuler la ventilation.',
        'not_found_or_unowned' => 'Transaction introuvable ou n\'appartenant pas à cet utilisateur.',
        'reconciled_split' => 'Cette transaction est rapprochée. Annule le rapprochement pour modifier sa ventilation.',
        'not_splittable' => 'Le type de transaction « :type » ne peut pas être ventilé.',
        'min_two_legs' => 'Une ventilation exige au moins 2 lignes.',
        'legs_non_zero' => 'Les montants des lignes ne peuvent pas être nuls.',
        'legs_parent_sign' => 'Les montants des lignes doivent avoir le même signe que la transaction principale.',
        'leg_category_not_accessible' => 'Catégorie de ligne introuvable ou inaccessible pour cet utilisateur.',
        'survivor_not_accessible' => 'Catégorie restante introuvable ou inaccessible pour cet utilisateur.',
        'survivor_must_be_current' => 'La catégorie restante doit être l\'une des catégories de ligne actuelles de la ventilation.',
    ],
];
