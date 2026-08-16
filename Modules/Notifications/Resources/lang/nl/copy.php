<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import voltooid',
        'receipts' => 'Nieuwe bonnen gevonden',
        'drift' => 'Een terugkerende afschrijving is gewijzigd',
        'forecast' => 'Kastekort op komst',
        'budget_nudge' => 'Budget bijna op',
        'savings_prompt' => 'Er is een goedkoper abonnement',
        'ics_statement_ready' => 'Nieuw ICS-overzicht beschikbaar',
        'payment_reminder_confident' => 'Betaling op :day',
        'payment_reminder_hedged' => 'Betaling rond :day',
        'position_digest_daily' => 'Je dagelijkse positie',
        'position_digest_weekly' => 'Je wekelijkse positie',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent van :budget besteed.',
        'receipts_matched' => ':count bon gekoppeld uit je e-mail.|:count bonnen gekoppeld uit je e-mail.',
        'import_finished' => ':count transactie geïmporteerd.|:count transacties geïmporteerd.',
        'drift' => 'Een terugkerende afschrijving ging :direction met :delta :currency.',
        'forecast' => 'Je verwachte saldo zakt binnen 30 dagen onder nul.',
        'ics_statement_ready' => 'Download het uit het ICS-portaal en zet het in Beatrax om de uitgaven van deze kaart bij te houden.',
        'payment_reminder_hedged' => ':name — verwacht rond :day, :amount.',
        'payment_reminder_confident' => ':name — verschuldigd op :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mnd)',
    ],

    'drift_direction' => [
        'up' => 'omhoog',
        'down' => 'omlaag',
    ],

    'digest' => [
        'nothing_notable' => 'Er vraagt niets om je aandacht.',
        'flow' => 'In :in, uit :out, netto :net.',
        'over_budget' => ':amount over budget tot nu toe.',
        'payments_due' => '1 betaling deze periode.|:count betalingen deze periode.',
        'shortfall' => 'Er komt een kastekort aan.',
    ],
];
