<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import voltooid',
        'receipts' => 'Nieuwe bonnen gevonden',
        'manual_entry' => 'Kasboek bijgewerkt',
        'migration_finished' => 'Migratie voltooid',
        'drift' => 'Een terugkerende afschrijving is gewijzigd',
        'forecast' => 'Kastekort op komst',
        'budget_nudge' => 'Budget bijna op',
        'budget_nudge_spent' => 'Budget op',
        'budget_nudge_over' => 'Budget overschreden',
        'savings_prompt' => 'Hier kun je besparen',
        'ics_statement_ready' => 'Nieuw ICS-overzicht beschikbaar',
        'payment_reminder_confident' => 'Betaling op :day (:date)',
        'payment_reminder_hedged' => 'Betaling rond :day (:date)',
        'position_digest_daily' => 'Je dagelijkse positie',
        'position_digest_weekly' => 'Je wekelijkse positie',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent van :budget besteed.',
        'receipts_matched' => ':count bon gekoppeld uit je e-mail.|:count bonnen gekoppeld uit je e-mail.',
        'import_finished' => ':count transactie geïmporteerd.|:count transacties geïmporteerd.',
        'manual_entry' => ':count boeking handmatig toegevoegd.|:count boekingen handmatig toegevoegd.',
        'migration_finished' => 'Je budget is overgezet, waaronder :count transactie.|Je budget is overgezet, waaronder :count transacties.',
        'drift' => 'Een terugkerende afschrijving ging :direction met :amount.',
        'forecast' => 'Je verwachte saldo zakt op :date onder nul.',
        'forecast_buffer' => 'Je verwachte saldo zakt op :date onder je buffer van :buffer.',
        'ics_statement_ready' => 'Download het uit het ICS-portaal en zet het in Beatrax om de uitgaven van deze kaart bij te houden.',
        'payment_reminder_hedged' => ':name — verwacht rond :day (:date), :amount.',
        'payment_reminder_confident' => ':name — verschuldigd op :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'omhoog',
        'down' => 'omlaag',
    ],

    'digest' => [
        'nothing_notable' => 'Er vraagt niets om je aandacht.',
        'flow' => 'In :in, uit :out, netto :net.',
        'over_budget' => ':amount over budget tot nu toe.',
        'payments_due' => ':count betaling deze periode.|:count betalingen deze periode.',
        'shortfall' => 'Er komt een kastekort aan.',
    ],
];
