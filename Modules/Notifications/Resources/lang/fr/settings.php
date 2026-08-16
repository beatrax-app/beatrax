<?php

declare(strict_types=1);

return [
    'what_heading' => 'Ce dont tu veux être averti',

    'reminders' => [
        'label' => 'Rappels de paiement',
        'help' => 'Sois prévenu avant l\'échéance d\'un paiement récurrent.',
    ],

    'lead_days' => [
        'label' => 'Me rappeler ___ jours avant',
        'help' => 'Combien de jours avant l\'échéance le rappel se déclenche. 1–30 jours.',
    ],

    'budget_nudges' => [
        'label' => 'Alertes de budget',
        'help' => 'Sois averti quand le budget d\'une catégorie est presque épuisé.',
    ],

    'digest' => [
        'label' => 'Ta situation de la semaine',
        'help' => 'À quelle fréquence tu reçois un résumé de la situation sur cette période.',
        'daily' => 'Chaque jour',
        'weekly' => 'Chaque semaine',
        'off' => 'Désactivé',
    ],

    'savings' => [
        'label' => 'Suggestions d\'économies',
        'help' => 'Sois averti quand Beatrax repère une offre moins chère ou une occasion d\'économiser.',
    ],

    'when_heading' => 'Quand et comment',

    'quiet_hours' => [
        'label' => 'Heures silencieuses',
        'help' => 'Aucun son ni bannière pendant cette plage — les notifications arrivent quand même dans ta boîte de réception.',
        'from' => 'De',
        'to' => 'À',
    ],

    'hide_details' => [
        'label' => 'Masquer les détails dans les notifications',
        'help' => 'Affiche les montants et les noms de commerçants dans la bannière de notification elle-même. Désactive si ton écran peut être vu par d\'autres.',
    ],

    'save' => 'Enregistrer les paramètres de notification',
    'saved' => 'Enregistré.',

    'other_devices' => [
        'summary' => 'Autres appareils',
        'empty' => 'Aucun autre appareil appairé pour l\'instant.',
        'unnamed' => 'Appareil sans nom',

        'summary_line' => 'rappels :reminders · alertes :nudges · résumé :digest · économies :savings',
        'on' => 'activé',
        'off' => 'désactivé',
    ],

    'errors' => [
        'save_failed' => 'Impossible d\'enregistrer tes paramètres de notification. Réessaie.',
    ],
];
