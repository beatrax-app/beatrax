<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importazione completata',
        'receipts' => 'Nuove ricevute trovate',
        'drift' => 'Un addebito ricorrente è cambiato',
        'forecast' => 'Scoperto di cassa in arrivo',
        'budget_nudge' => 'Budget quasi esaurito',
        'savings_prompt' => 'Esiste un piano più economico',
        'ics_statement_ready' => 'Nuovo estratto conto ICS disponibile',
        'payment_reminder_confident' => 'Pagamento in scadenza :day',
        'payment_reminder_hedged' => 'Pagamento in scadenza intorno a :day',
        'position_digest_daily' => 'La tua situazione giornaliera',
        'position_digest_weekly' => 'La tua situazione settimanale',
    ],

    'body' => [
        'budget_nudge' => ':category — spesi :spent di :budget.',
        'receipts_matched' => ':count ricevuta abbinata dalla tua email.|:count ricevute abbinate dalla tua email.',
        'import_finished' => ':count transazione importata.|:count transazioni importate.',
        'drift' => 'Un addebito ricorrente si è mosso :direction di :delta :currency.',
        'forecast' => 'Il tuo saldo previsto scende sotto zero nei prossimi 30 giorni.',
        'ics_statement_ready' => 'Scaricalo dal portale ICS e trascinalo in Beatrax per tenere aggiornate le spese di questa carta.',
        'payment_reminder_hedged' => ':name — previsto intorno a :day, :amount.',
        'payment_reminder_confident' => ':name — in scadenza :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mese)',
    ],

    'drift_direction' => [
        'up' => 'al rialzo',
        'down' => 'al ribasso',
    ],

    'digest' => [
        'nothing_notable' => 'Niente richiede la tua attenzione.',
        'flow' => 'Entrate :in, uscite :out, netto :net.',
        'over_budget' => ':amount oltre il budget finora.',
        'payments_due' => '1 pagamento in scadenza in questo periodo.|:count pagamenti in scadenza in questo periodo.',
        'shortfall' => 'Si prospetta uno scoperto di cassa.',
    ],
];
