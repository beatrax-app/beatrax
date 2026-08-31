<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgets',
        'subtitle' => 'Affecte tout — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Période précédente',
        'next_aria' => 'Période suivante',
    ],

    'ready' => [
        'label' => 'Prêt à affecter',
        'overassigned' => 'Tu as affecté plus que ce dont tu disposes — réduis une enveloppe ou attends de nouveaux revenus.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Rien d\'affecté pour l\'instant',
        'copy_hint' => 'Copie le plan du mois dernier, ou clique dans une cellule ci-dessous pour commencer à affecter.',
        'first_hint' => 'Clique dans une cellule ci-dessous pour affecter ton premier mois.',
        'copy_button' => 'Copier le mois dernier',
    ],

    'no_categories' => [
        'heading' => 'Aucune catégorie de dépense pour l\'instant',
        'body' => 'Ajoute une catégorie de dépense pour commencer à lui affecter de l\'argent.',
    ],

    'table' => [
        'category' => 'Catégorie',
        'assigned' => 'Affecté',
        'carried_in' => 'Reporté',
        'moved' => 'Déplacé',
        'spent' => 'Dépensé',
        'available' => 'Disponible',
        'if_overspent' => 'En cas de dépassement',
        'notify_at' => 'Alerter à',
        'actions' => 'Actions',
    ],

    'badge' => [
        'carries_negative' => 'Reporte le négatif',
        'unconverted_aria' => 'Les dépenses dans une devise sans taux disponible ne sont pas comptées ici — voir le tableau de bord',
        'unconverted_title' => 'Les dépenses sans taux disponible ne sont pas comptées ici — voir le tableau de bord',
        'over_budget' => ':count au-dessus du budget',
    ],

    'row' => [
        'assigned_aria' => 'Affecté pour :category',
        'overspend_aria' => 'Si :category est dépassée',
        'notify_aria' => 'M\'alerter au pourcentage utilisé pour :category',
        'move_money' => 'Déplacer de l\'argent',
        'move' => 'Déplacer',
    ],

    'overspend' => [
        'reduce' => 'Réduire le montant à affecter du mois prochain',
        'carry' => 'Reporter le négatif dans cette enveloppe',
    ],

    'history' => [
        'show' => 'Afficher l\'historique ↓',
        'hide' => 'Masquer l\'historique ↑',
        'moved_from' => 'Déplacé depuis :category',
        'moved_to' => 'Déplacé vers :category',
        'moved_unreadable' => 'Déplacé avec :category par une version plus récente de Beatrax',
        'undo' => 'Annuler',
    ],

    'phone' => [
        'spent' => 'Dépensé :amount',
        'carried_in' => 'Reporté :amount',
        'moved' => 'Déplacé :amount',
        'available' => 'Disponible :amount',
        'notify_at' => 'Alerter à',
    ],

    'modal' => [
        'move_from' => 'Déplacer depuis :name',
        'move_from_fallback' => 'enveloppe',
        'move_to' => 'Déplacer vers',
        'no_other' => 'Aucune autre enveloppe',
        'select' => 'Choisis une enveloppe',
        'amount' => 'Montant',
        'available_in' => 'Disponible dans :name : :amount',
        'note' => 'Note (facultatif)',
        'note_placeholder' => 'ex. Couvrir le dépassement restaurants',
        'cancel' => 'Annuler',
        'move_funds' => 'Déplacer les fonds',
    ],

    'glance' => [
        'see_all' => 'Tout voir →',
    ],

    'notices' => [
        'invalid_amount' => 'Saisis un montant valide.',
        'threshold_range' => 'Saisis un nombre entier entre 1 et 200.',
        'copied_last_month' => 'Plan du mois dernier copié.',
        'choose_envelope' => 'Choisis une enveloppe vers laquelle déplacer l\'argent.',
        'amount_positive' => 'Saisis un montant supérieur à zéro.',
        'move_failed' => 'Le déplacement n\'a pas pu être effectué — réessaie.',
        'money_moved' => 'Argent déplacé.',
        'move_undone' => 'Déplacement annulé.',
    ],

    'errors' => [
        'assigned_negative' => 'Le montant affecté ne peut pas être négatif.',
        'invalid_overspend_mode' => 'Mode de dépassement invalide.',
        'threshold_range' => 'Le seuil d\'alerte doit être compris entre 1 et 200.',
        'same_envelope' => 'L\'enveloppe source et l\'enveloppe de destination doivent être différentes.',
        'non_positive_amount' => 'Montant invalide ou non positif.',
        'category_not_found' => 'Catégorie introuvable ou inaccessible pour l\'utilisateur.',
    ],
];
