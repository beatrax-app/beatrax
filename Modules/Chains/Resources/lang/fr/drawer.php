<?php

declare(strict_types=1);

return [
    'heading_named' => 'Chaîne pour :name',
    'heading' => 'Chaîne',

    'unresolved_heading' => 'Chaîne pas encore résolue',
    'unresolved_body' => 'Le résolveur de chaînes tourne encore. Ouvre la file de vérification ou actualise dans un instant.',

    'none_heading' => 'Aucune chaîne de financement trouvée',
    'none_body' => 'Aucune chaîne de financement n\'a été détectée pour cette transaction. Si tu en attendais une, propose un candidat depuis la file de vérification.',

    'none_beyond_leg' => 'Aucune chaîne de financement trouvée au-delà de ce maillon.',

    'covers_charges' => 'Couvre :count débits ICS',
    'no_ics_charges' => 'Aucun débit ICS dans ce règlement',
    'show_more_fanout' => 'Afficher :count de plus · :shown sur :total',

    'confirm' => 'Confirmer',
    'reject' => 'Rejeter',
    'confirm_aria' => 'Confirmer le maillon de chaîne :id',
    'reject_aria' => 'Rejeter le maillon de chaîne :id',

    'confidence_aria' => [
        'deterministic' => 'Confiance : correspondance déterministe',
        'confirmed' => 'Confiance : confirmée',
        'candidate' => 'Confiance : candidat ; à examiner',
    ],
];
