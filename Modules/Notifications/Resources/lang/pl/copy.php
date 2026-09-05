<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import zakończony',
        'receipts' => 'Znaleziono nowe paragony',
        'manual_entry' => 'Zaktualizowano księgę kasową',
        'migration_finished' => 'Migracja zakończona',
        'drift' => 'Zmieniła się opłata cykliczna',
        'forecast' => 'Zbliża się niedobór środków',
        'budget_nudge' => 'Budżet prawie wyczerpany',
        'budget_nudge_spent' => 'Budżet wyczerpany',
        'budget_nudge_over' => 'Budżet przekroczony',
        'savings_prompt' => 'Miejsce, w którym możesz zaoszczędzić',
        'ics_statement_ready' => 'Nowy wyciąg ICS jest gotowy',
        'payment_reminder_confident' => 'Termin płatności: :day (:date)',
        'payment_reminder_hedged' => 'Termin płatności: około :day (:date)',
        'position_digest_daily' => 'Twoja dzienna sytuacja',
        'position_digest_weekly' => 'Twoja tygodniowa sytuacja',
    ],

    'body' => [
        'budget_nudge' => ':category — wydano :spent z :budget.',
        'receipts_matched' => 'Dopasowano :count paragon z Twojej poczty.|Dopasowano :count paragony z Twojej poczty.|Dopasowano :count paragonów z Twojej poczty.',
        'import_finished' => 'Zaimportowano :count transakcję.|Zaimportowano :count transakcje.|Zaimportowano :count transakcji.',
        'manual_entry' => 'Ręcznie dodano :count wpis.|Ręcznie dodano :count wpisy.|Ręcznie dodano :count wpisów.',
        'migration_finished' => 'Twój budżet został przeniesiony, w tym :count transakcja.|Twój budżet został przeniesiony, w tym :count transakcje.|Twój budżet został przeniesiony, w tym :count transakcji.',
        'drift' => 'Opłata cykliczna poszła :direction o :amount.',
        'forecast' => 'Twoje prognozowane saldo spadnie poniżej zera :date.',
        'forecast_buffer' => 'Twoje prognozowane saldo spadnie poniżej Twojego bufora :buffer :date.',
        'ics_statement_ready' => 'Pobierz go z portalu ICS i wgraj do Beatrax, aby wydatki z tej karty były aktualne.',
        'payment_reminder_hedged' => ':name — spodziewane około :day (:date), :amount.',
        'payment_reminder_confident' => ':name — termin :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'w górę',
        'down' => 'w dół',
    ],

    'digest' => [
        'nothing_notable' => 'Nic nie wymaga Twojej uwagi.',
        'flow' => 'Przych. :in, wych. :out, netto :net.',
        'net_worth' => 'Wartość netto :amount.',
        'over_budget' => 'Dotychczas ponad budżet: :amount.',
        'payments_due' => ':count płatność w tym okresie.|:count płatności w tym okresie.|:count płatności w tym okresie.',
        'shortfall' => 'Zbliża się niedobór środków.',
        'forecast_not_run' => 'Prognoza przepływów pieniężnych jeszcze nie została wykonana.',
    ],
];
