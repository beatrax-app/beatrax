<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importazione completata',
        'receipts' => 'Nuove ricevute trovate',
        'manual_entry' => 'Libro cassa aggiornato',
        'migration_finished' => 'Migrazione completata',
        'drift' => 'Un addebito ricorrente è cambiato',
        'forecast' => 'Scoperto di cassa in arrivo',
        'budget_nudge' => 'Budget quasi esaurito',
        'budget_nudge_spent' => 'Budget esaurito',
        'budget_nudge_over' => 'Budget superato',
        'savings_prompt' => 'Un punto dove potresti risparmiare',
        'ics_statement_ready' => 'Nuovo estratto conto ICS disponibile',
        'payment_reminder_confident' => 'Pagamento in scadenza :day (:date)',
        'payment_reminder_hedged' => 'Pagamento in scadenza intorno a :day (:date)',
        'position_digest_daily' => 'La tua situazione giornaliera',
        'position_digest_weekly' => 'La tua situazione settimanale',
    ],

    'body' => [
        'budget_nudge' => ':category — spesi :spent di :budget.',
        'receipts_matched' => ':count ricevuta abbinata dalla tua email.|:count ricevute abbinate dalla tua email.',
        'import_finished' => ':count transazione importata.|:count transazioni importate.',
        'manual_entry' => ':count voce aggiunta a mano.|:count voci aggiunte a mano.',
        'migration_finished' => 'Il tuo budget è stato trasferito, inclusa :count transazione.|Il tuo budget è stato trasferito, incluse :count transazioni.',
        'drift' => 'Un addebito ricorrente si è mosso :direction di :amount.',
        'forecast' => 'Il tuo saldo previsto scende sotto zero il :date.',
        'forecast_buffer' => 'Il tuo saldo previsto scende sotto la tua riserva di :buffer il :date.',
        'ics_statement_ready' => 'Scaricalo dal portale ICS e trascinalo in Beatrax per tenere aggiornate le spese di questa carta.',
        'payment_reminder_hedged' => ':name — previsto intorno a :day (:date), :amount.',
        'payment_reminder_confident' => ':name — in scadenza :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'al rialzo',
        'down' => 'al ribasso',
    ],

    'digest' => [
        'nothing_notable' => 'Niente richiede la tua attenzione.',
        'flow' => 'Entrate :in, uscite :out, netto :net.',
        'net_worth' => 'Patrimonio netto :amount.',
        'over_budget' => ':amount oltre il budget finora.',
        'payments_due' => ':count pagamento in scadenza in questo periodo.|:count pagamenti in scadenza in questo periodo.',
        'shortfall' => 'Si prospetta uno scoperto di cassa.',
        'forecast_not_run' => 'Non è ancora stata calcolata una previsione di cassa.',
    ],
];
