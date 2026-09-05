<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import terminé',
        'receipts' => 'Nouveaux reçus trouvés',
        'manual_entry' => 'Livre de caisse mis à jour',
        'migration_finished' => 'Migration terminée',
        'drift' => 'Un débit récurrent a changé',
        'forecast' => 'Déficit de trésorerie à venir',
        'budget_nudge' => 'Budget presque épuisé',
        'budget_nudge_spent' => 'Budget épuisé',
        'budget_nudge_over' => 'Budget dépassé',
        'savings_prompt' => 'Un poste où tu pourrais économiser',
        'ics_statement_ready' => 'Nouveau relevé ICS disponible',
        'payment_reminder_confident' => 'Paiement dû :day (:date)',
        'payment_reminder_hedged' => 'Paiement dû vers :day (:date)',
        'position_digest_daily' => 'Ta situation du jour',
        'position_digest_weekly' => 'Ta situation de la semaine',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent sur :budget dépensés.',
        'receipts_matched' => ':count reçu associé depuis tes e-mails.|:count reçus associés depuis tes e-mails.',
        'import_finished' => ':count transaction importée.|:count transactions importées.',
        'manual_entry' => ':count entrée ajoutée à la main.|:count entrées ajoutées à la main.',
        'migration_finished' => 'Ton budget a été transféré, dont :count transaction.|Ton budget a été transféré, dont :count transactions.',
        'drift' => 'Un débit récurrent a bougé :direction de :amount.',
        'forecast' => 'Ton solde prévisionnel passe sous zéro le :date.',
        'forecast_buffer' => 'Ton solde prévisionnel passe sous ta réserve de :buffer le :date.',
        'ics_statement_ready' => 'Télécharge-le depuis le portail ICS et dépose-le dans Beatrax pour garder les dépenses de cette carte à jour.',
        'payment_reminder_hedged' => ':name — attendu vers :day (:date), :amount.',
        'payment_reminder_confident' => ':name — dû :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'à la hausse',
        'down' => 'à la baisse',
    ],

    'digest' => [
        'nothing_notable' => 'Rien ne demande ton attention.',
        'flow' => 'Entrées :in, sorties :out, net :net.',
        'net_worth' => 'Valeur nette :amount.',
        'over_budget' => ':amount de dépassement de budget jusqu\'ici.',
        'payments_due' => ':count paiement dû sur cette période.|:count paiements dus sur cette période.',
        'shortfall' => 'Un déficit de trésorerie approche.',
        'forecast_not_run' => 'Aucune prévision de trésorerie n\'a encore été calculée.',
    ],
];
