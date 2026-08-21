<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importas baigtas',
        'receipts' => 'Rasta naujų kvitų',
        'drift' => 'Pasikartojantis mokėjimas pasikeitė',
        'forecast' => 'Artėja lėšų trūkumas',
        'budget_nudge' => 'Biudžetas beveik išnaudotas',
        'savings_prompt' => 'Yra pigesnis planas',
        'ics_statement_ready' => 'Naujas ICS išrašas',
        'payment_reminder_confident' => 'Mokėjimas :day',
        'payment_reminder_hedged' => 'Mokėjimas apie :day',
        'position_digest_daily' => 'Tavo dienos apžvalga',
        'position_digest_weekly' => 'Tavo savaitės apžvalga',
    ],

    'body' => [
        'budget_nudge' => ':category — išleista :spent iš :budget.',
        'receipts_matched' => 'Iš el. pašto susietas :count kvitas.|Iš el. pašto susieti :count kvitai.|Iš el. pašto susieta :count kvitų.',
        'import_finished' => 'Importuota :count operacija.|Importuotos :count operacijos.|Importuota :count operacijų.',
        'drift' => 'Pasikartojantis mokėjimas :direction :amount.',
        'forecast' => 'Prognozuojamas likutis per artimiausias 30 dienų nukris žemiau nulio.',
        'ics_statement_ready' => 'Atsisiųsk jį iš ICS portalo ir įkelk į Beatrax, kad šios kortelės išlaidos būtų atnaujintos.',
        'payment_reminder_hedged' => ':name — laukiama apie :day, :amount.',
        'payment_reminder_confident' => ':name — mokėtina :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mėn.)',
    ],

    'drift_direction' => [
        'up' => 'padidėjo',
        'down' => 'sumažėjo',
    ],

    'digest' => [
        'nothing_notable' => 'Tavo dėmesio niekas nereikalauja.',
        'flow' => 'Gauta :in, išleista :out, grynasis :net.',
        'over_budget' => 'Iki šiol biudžetas viršytas :amount.',
        'payments_due' => 'Šį laikotarpį mokėtinas :count mokėjimas.|Šį laikotarpį mokėtini :count mokėjimai.|Šį laikotarpį mokėtina :count mokėjimų.',
        'shortfall' => 'Artėja lėšų trūkumas.',
    ],
];
