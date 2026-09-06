<?php

declare(strict_types=1);

return [
    'heading_named' => 'Chaîne pour :name',
    'heading' => 'Chaîne',

    'unresolved_heading' => 'Aucune transaction sélectionnée',
    'unresolved_body' => "Choisis une ligne dans la liste des transactions pour voir ce qui l'a payée.",

    'none_heading' => 'Aucune chaîne de financement trouvée',
    'none_body' => 'Aucune chaîne de financement n\'a été détectée pour cette transaction. Si tu en attendais une, propose un candidat depuis la file de vérification.',

    'none_beyond_leg' => 'Aucune chaîne de financement trouvée au-delà de ce maillon.',

    'covers_charges' => 'Couvre :count débit ICS|Couvre :count débits ICS',
    'show_more_fanout' => 'Afficher :count de plus · :shown sur :total',

    'confirm' => 'Confirmer',
    'reject' => 'Rejeter',
    'confirm_aria' => 'Confirmer le maillon de chaîne :id',
    'reject_aria' => 'Rejeter le maillon de chaîne :id',

    'confidence_tier' => [
        'deterministic' => 'Déterministe',
        'confirmed' => 'Confirmée',
        'candidate' => 'Candidat',
    ],

    'confidence_aria' => [
        'deterministic' => 'Confiance : correspondance déterministe',
        'confirmed' => 'Confiance : confirmée',
        'candidate' => 'Confiance : candidat ; à examiner',
    ],
];
