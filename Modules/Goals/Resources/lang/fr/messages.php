<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Objectifs',
        'subtitle' => 'Suis ta progression vers tes objectifs d\'épargne.',
        'add_goal' => 'Ajouter un objectif',
    ],

    'empty' => [
        'heading' => 'Aucun objectif pour l\'instant',
        'body' => 'Fixe un montant cible et une date pour commencer à suivre ta progression d\'épargne.',
        'add_first' => 'Ajoute ton premier objectif',
    ],

    'status' => [
        'overdue' => 'En retard',
        'reached' => 'Atteint',
        'completed' => 'Terminé',
        'archived' => 'Archivé',
    ],

    'row' => [
        'edit' => 'Modifier',
    ],

    'progress' => [
        'aria' => ':name : :pct % atteint',
    ],

    'projection' => [
        'target_reached' => 'Objectif atteint',
        'add_contributions' => 'Ajoute des versements pour voir une prévision',
        'not_enough_history' => 'Pas encore assez d\'historique pour estimer une date',
        'est' => 'Est. :date ·',
        'projection_note' => '(prévision)',
        'projected' => 'Prévu : :date',
    ],

    'archive' => [
        'confirm_question' => 'Archiver cet objectif ?',
        'close' => 'Fermer',
        'confirm_aria' => 'Confirmer l\'archivage de :name',
        'archive' => 'Archiver',
    ],

    'actions' => [
        'more_aria' => 'Plus d\'actions pour :name',
        'mark_complete' => 'Marquer comme terminé',
        'archive' => 'Archiver',
        'restore' => 'Restaurer',
    ],

    'archived_disclosure' => 'Objectifs archivés (:count)',

    'form' => [
        'title_edit' => 'Modifier l\'objectif',
        'title_create' => 'Créer un objectif d\'épargne',
        'subtitle_edit' => 'Modifie le nom, le montant cible, la date ou la cagnotte liée.',
        'subtitle_create' => 'Fixe un montant cible et une date pour suivre ta progression d\'épargne.',
        'name' => 'Nom',
        'name_placeholder' => 'ex. Fonds d\'urgence',
        'target_amount' => 'Montant cible (:currency)',
        'target_date' => 'Date cible',
        'linked_pot' => 'Cagnotte liée (facultatif)',
        'no_pot' => 'Aucune cagnotte — suivre les virements',
        'linked_pot_help' => 'Une fois liée, le solde de la cagnotte détermine la progression de cet objectif.',
        'save_changes' => 'Enregistrer les modifications',
        'save_goal' => 'Enregistrer l\'objectif',
        'close' => 'Fermer',
    ],

    'summary' => [
        'see_all' => 'Tout voir →',
        'no_goals' => 'Aucun objectif pour l\'instant.',
        'add_first' => 'Ajoute ton premier objectif →',
    ],

    'notices' => [
        'goal_created' => 'Objectif créé.',
        'goal_updated' => 'Objectif mis à jour.',
        'goal_marked_complete' => 'Objectif marqué comme terminé.',
        'goal_archived' => 'Objectif archivé.',
        'goal_restored' => 'Objectif restauré.',
    ],

    'errors' => [
        'name' => 'Saisis un nom pour ton objectif.',
        'date' => 'Choisis une date cible.',
        'amount' => 'Saisis un montant valide supérieur à zéro.',
        'pot_linked_category' => 'Cette cagnotte est liée à une catégorie. Supprime d\'abord ce lien sur la page Cagnottes.',
    ],
];
