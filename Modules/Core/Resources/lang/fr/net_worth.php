<?php

declare(strict_types=1);

return [
    'aria' => 'Patrimoine net',
    'heading' => 'Patrimoine net',

    'rate_details' => 'Détails du taux',
    'rate_details_for' => 'Détails du taux pour :name',

    'across' => 'sur :count compte|sur :count comptes',

    'not_converted' => '· :count compte non converti — aucun taux disponible|· :count comptes non convertis — aucun taux disponible',
    'no_rate_available' => '· aucun taux disponible',

    'toggle_hide' => 'Masquer',
    'toggle_breakdown' => 'Répartition',
    'card_suffix' => '(carte)',

    'converted_to' => 'Converti en :currency',
    'as_of' => 'au :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'taux au :date issus de :source',

    'stale_bundled' => 'Beatrax utilise un taux de l’instantané intégré, vieux de plus de :count jour. Active l’actualisation en ligne dans les Paramètres pour des taux à jour.|Beatrax utilise un taux de l’instantané intégré, vieux de plus de :count jours. Active l’actualisation en ligne dans les Paramètres pour des taux à jour.',
    'stale_old' => 'Ce taux date de plus de :count jour. La prochaine actualisation en ligne le mettra à jour.|Ce taux date de plus de :count jours. La prochaine actualisation en ligne le mettra à jour.',
    'stale_offline' => 'Ce taux date de plus de :count jour et l’actualisation en ligne est désactivée. Active-la dans les Paramètres pour le mettre à jour.|Ce taux date de plus de :count jours et l’actualisation en ligne est désactivée. Active-la dans les Paramètres pour le mettre à jour.',

    'source_ecb' => 'BCE',
    'source_bundled' => 'Instantané intégré',
    'source_transaction' => 'Taux enregistré',
    'source_fallback' => 'taux',
];
