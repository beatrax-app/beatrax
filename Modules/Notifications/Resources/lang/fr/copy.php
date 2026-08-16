<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import terminé',
        'receipts' => 'Nouveaux reçus trouvés',
        'drift' => 'Un débit récurrent a changé',
        'forecast' => 'Déficit de trésorerie à venir',
        'budget_nudge' => 'Budget presque épuisé',
        'savings_prompt' => 'Il existe une offre moins chère',
        'ics_statement_ready' => 'Nouveau relevé ICS disponible',
        'payment_reminder_confident' => 'Paiement dû :day',
        'payment_reminder_hedged' => 'Paiement dû vers :day',
        'position_digest_daily' => 'Ta situation du jour',
        'position_digest_weekly' => 'Ta situation de la semaine',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent sur :budget dépensés.',
        'receipts_matched' => ':count reçu associé depuis tes e-mails.|:count reçus associés depuis tes e-mails.',
        'import_finished' => ':count transaction importée.|:count transactions importées.',
        'drift' => 'Un débit récurrent a bougé :direction de :delta :currency.',
        'forecast' => 'Ton solde prévisionnel passe sous zéro dans les 30 prochains jours.',
        'ics_statement_ready' => 'Télécharge-le depuis le portail ICS et dépose-le dans Beatrax pour garder les dépenses de cette carte à jour.',
        'payment_reminder_hedged' => ':name — attendu vers :day, :amount.',
        'payment_reminder_confident' => ':name — dû :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mois)',
    ],

    'drift_direction' => [
        'up' => 'à la hausse',
        'down' => 'à la baisse',
    ],

    'digest' => [
        'nothing_notable' => 'Rien ne demande ton attention.',
        'flow' => 'Entrées :in, sorties :out, net :net.',
        'over_budget' => ':amount de dépassement de budget jusqu\'ici.',
        'payments_due' => ':count paiement dû sur cette période.|:count paiements dus sur cette période.',
        'shortfall' => 'Un déficit de trésorerie approche.',
    ],
];
