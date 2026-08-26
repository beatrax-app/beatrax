<?php

declare(strict_types=1);

return [
    'title' => 'Vérifier les récurrences',
    'subtitle' => 'Approuve, reporte ou rejette les suggestions de récurrences détectées.',

    'tabs' => [
        'pending' => 'En attente',
        'rejected' => 'Rejetées',
        'cadence_changed' => 'Fréquence modifiée',
    ],

    'bulk' => [
        'aria' => 'Actions groupées',
        'selected' => ':count sélectionnées',
        'approve' => 'Approuver :count',
        'reject' => 'Rejeter :count',
    ],

    'empty' => [
        'heading' => 'Rien à vérifier',
        'pending' => 'Les suggestions de récurrences arrivent ici dès que le détecteur repère des groupes mensuels stables.',
        'rejected' => 'Les suggestions rejetées apparaissent ici pour que tu puisses les rétablir si tu changes d\'avis.',
        'cadence_changed' => 'Les séries approuvées dont la fréquence a changé réapparaissent ici pour une nouvelle vérification.',
    ],

    'next' => 'Prochain',
    'overdue' => 'En retard',
    'cadence_changed_note' => 'fréquence modifiée',
    'un_reject' => 'Annuler le rejet',
    'approve' => 'Approuver',
    'approve_aria' => 'Approuver la série récurrente :id',
    'reject' => 'Rejeter',
    'reject_aria' => 'Rejeter la série récurrente :id',
    'snooze' => 'Reporter',
    'snooze_aria' => 'Reporter la série récurrente :id',
    'snooze_1w' => '1 semaine',
    'snooze_1m' => '1 mois',
    'snooze_3m' => '3 mois',
    'edit_name' => 'Modifier le nom',
    'edit_name_aria' => 'Renommer la série récurrente :id',
    'new_name_label' => 'Nouveau nom pour cette série',
    'save' => 'Enregistrer',

    'toast' => [
        'approved' => 'Approuvée',
        'rejected' => 'Rejetée',
        'snoozed' => 'Reportée',
        'renamed' => 'Renommée',
        'un_rejected' => 'Rejet annulé',
        'bulk_approved' => ':count approuvées',
        'bulk_rejected' => ':count rejetées',
    ],
];
