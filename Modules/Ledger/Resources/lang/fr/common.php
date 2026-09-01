<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Non catégorisé',
    'unavailable_category' => 'Catégorie absente de cet appareil',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Compensée',
        'uncleared' => 'Non compensée',
        'reconciled' => 'Rapprochée',
    ],

    'badge' => [

        'reconciled_hint' => 'Rapprochée — annule le rapprochement pour changer le statut.',
        'toggle_aria' => ':label — clique pour basculer',
        // i18n-review: fr · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — touche pour basculer',
    ],
];
