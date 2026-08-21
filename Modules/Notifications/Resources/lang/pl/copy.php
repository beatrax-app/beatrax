<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import zakończony',
        'receipts' => 'Znaleziono nowe paragony',
        'drift' => 'Zmieniła się opłata cykliczna',
        'forecast' => 'Zbliża się niedobór środków',
        'budget_nudge' => 'Budżet prawie wyczerpany',
        'savings_prompt' => 'Istnieje tańszy plan',
        'ics_statement_ready' => 'Nowy wyciąg ICS jest gotowy',
        'payment_reminder_confident' => 'Termin płatności: :day',
        'payment_reminder_hedged' => 'Termin płatności: około :day',
        'position_digest_daily' => 'Twoja dzienna sytuacja',
        'position_digest_weekly' => 'Twoja tygodniowa sytuacja',
    ],

    'body' => [
        'budget_nudge' => ':category — wydano :spent z :budget.',
        'receipts_matched' => 'Dopasowano :count paragon z Twojej poczty.|Dopasowano :count paragony z Twojej poczty.|Dopasowano :count paragonów z Twojej poczty.',
        'import_finished' => 'Zaimportowano :count transakcję.|Zaimportowano :count transakcje.|Zaimportowano :count transakcji.',
        'drift' => 'Opłata cykliczna poszła :direction o :amount.',
        'forecast' => 'Twoje prognozowane saldo spadnie poniżej zera w ciągu najbliższych 30 dni.',
        'ics_statement_ready' => 'Pobierz go z portalu ICS i wgraj do Beatrax, aby wydatki z tej karty były aktualne.',
        'payment_reminder_hedged' => ':name — spodziewane około :day, :amount.',
        'payment_reminder_confident' => ':name — termin :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mies.)',
    ],

    'drift_direction' => [
        'up' => 'w górę',
        'down' => 'w dół',
    ],

    'digest' => [
        'nothing_notable' => 'Nic nie wymaga Twojej uwagi.',
        'flow' => 'Przych. :in, wych. :out, netto :net.',
        'over_budget' => 'Dotychczas ponad budżet: :amount.',
        'payments_due' => '1 płatność w tym okresie.|:count płatności w tym okresie.|:count płatności w tym okresie.',
        'shortfall' => 'Zbliża się niedobór środków.',
    ],
];
