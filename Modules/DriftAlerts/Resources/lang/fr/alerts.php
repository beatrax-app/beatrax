<?php

declare(strict_types=1);

return [
    'page_title' => 'Alertes',
    'heading' => 'Alertes',
    'intro_anomaly' => 'Débits isolés qui sortent de l\'ordinaire pour toi.',
    'intro_drift' => 'Séries récurrentes approuvées dont le dernier débit est sorti de ton seuil.',
    'adjust_threshold' => 'Ajuster le seuil →',
    'adjust_sensitivity' => 'Ajuster la sensibilité →',

    'type_aria' => 'Type d\'alerte',
    'type' => [
        'drift' => 'Dérive des abonnements',
        'anomaly' => 'Débits inhabituels',
    ],

    'lifecycle_aria' => 'Cycle de vie de l\'alerte',
    'tabs' => [
        'open' => 'En cours',
        'history' => 'Historique',
        'dismissed' => 'Ignorées',
    ],

    'load_more' => 'Afficher plus',
    'group_count' => ':count dérive en cours|:count dérives en cours',

    'anomaly_empty' => [
        'open_heading' => 'Aucun débit inhabituel',
        'open_body' => 'Beatrax surveille tes dépenses et signale les débits qui sortent de l\'ordinaire. Dès que quelque chose d\'inhabituel arrive, ça s\'affiche ici.',
        'history_heading' => 'Aucun débit pris en compte pour l\'instant',
        'history_body' => 'Les débits que tu as pris en compte apparaîtront ici, pour que tu voies ce que tu as déjà examiné.',
        'dismissed_heading' => 'Rien d\'ignoré pour l\'instant',
        'dismissed_body' => 'Quand tu marques un débit comme attendu, il atterrit ici avec sa règle d\'exclusion.',
    ],

    'empty_open' => [
        'heading' => 'Aucune alerte de dérive en cours',
        'body' => 'Beatrax surveille tes séries récurrentes approuvées et signale celles dont le dernier débit s\'écarte du montant précédent de plus que ton seuil. Ajuste le seuil dans',
        'link' => 'Paramètres → Alerte de dérive par défaut',
    ],
    'empty_history' => [
        'heading' => 'Aucune dérive prise en compte pour l\'instant',
        'body' => 'Les alertes de dérive prises en compte apparaîtront ici, pour que tu voies ce que tu as déjà examiné.',
    ],
    'empty_dismissed' => [
        'heading' => 'Rien d\'ignoré pour l\'instant',
        'body' => 'Quand tu indiques à Beatrax que tu as résilié une série, cette décision atterrit ici avec un horodatage.',
    ],

    'row' => [
        'per_year' => '/an',
        'meta_prior_now' => 'avant :prior → maintenant :now',
        'meta_detected' => 'détecté le :date',
        'meta_threshold' => 'seuil ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/an)',
        'cancel_impact' => 'Résilier → économise :amount/an',
        'cadence_flipped' => 'Fréquence modifiée — visible aussi dans',
        'cadence_flipped_link' => 'Vérifier les récurrences',
        'acknowledge' => 'Prendre en compte',
        'acknowledge_aria' => 'Prendre en compte l\'alerte de dérive :id',
        'snooze' => 'Reporter ▾',
        'snooze_1w' => '1 semaine',
        'snooze_1m' => '1 mois',
        'snooze_3m' => '3 mois',
        'model_cancel' => 'Simuler la résiliation ↗',
        'model_cancel_aria' => 'Simuler la résiliation — simule la résiliation dans la prévision pour l\'alerte de dérive :id',
        'cancelled' => 'J\'ai résilié',
        'cancelled_aria' => 'J\'ai résilié — ignore l\'alerte de dérive :id comme résiliée',
    ],

    'toasts' => [
        'acknowledged' => 'Pris en compte',
        'snoozed' => 'Reporté',
        'dismissed' => 'Ignorée',
        'suppression_added' => 'Règle d\'exclusion ajoutée — Annuler',
        'dismissed_expected' => 'Ignorée car attendue',
        'reopened' => 'Rouverte',
        'dismissed_cancelled' => 'Ignorée car résiliée',
    ],
];
