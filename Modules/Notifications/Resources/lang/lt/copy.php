<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importas baigtas',
        'receipts' => 'Rasta naujų kvitų',
        'manual_entry' => 'Kasos knyga atnaujinta',
        'migration_finished' => 'Perkėlimas baigtas',
        'drift' => 'Pasikartojantis mokėjimas pasikeitė',
        'forecast' => 'Artėja lėšų trūkumas',
        'budget_nudge' => 'Biudžetas beveik išnaudotas',
        'budget_nudge_spent' => 'Biudžetas išnaudotas',
        'budget_nudge_over' => 'Biudžetas viršytas',
        'savings_prompt' => 'Vieta, kur galėtum sutaupyti',
        'ics_statement_ready' => 'Naujas ICS išrašas',
        'payment_reminder_confident' => 'Mokėjimas :day (:date)',
        'payment_reminder_hedged' => 'Mokėjimas apie :day (:date)',
        'position_digest_daily' => 'Tavo dienos apžvalga',
        'position_digest_weekly' => 'Tavo savaitės apžvalga',
    ],

    'body' => [
        'budget_nudge' => ':category — išleista :spent iš :budget.',
        'receipts_matched' => 'Iš el. pašto susietas :count kvitas.|Iš el. pašto susieti :count kvitai.|Iš el. pašto susieta :count kvitų.',
        'import_finished' => 'Importuota :count operacija.|Importuotos :count operacijos.|Importuota :count operacijų.',
        'manual_entry' => 'Ranka pridėtas :count įrašas.|Ranka pridėti :count įrašai.|Ranka pridėta :count įrašų.',
        'migration_finished' => 'Tavo biudžetas perkeltas, įskaitant :count operaciją.|Tavo biudžetas perkeltas, įskaitant :count operacijas.|Tavo biudžetas perkeltas, įskaitant :count operacijų.',
        'drift' => 'Pasikartojantis mokėjimas :direction :amount.',
        'forecast' => 'Prognozuojamas likutis :date nukris žemiau nulio.',
        'forecast_buffer' => 'Prognozuojamas likutis :date nukris žemiau tavo :buffer atsargos.',
        'ics_statement_ready' => 'Atsisiųsk jį iš ICS portalo ir įkelk į Beatrax, kad šios kortelės išlaidos būtų atnaujintos.',
        'payment_reminder_hedged' => ':name — laukiama apie :day (:date), :amount.',
        'payment_reminder_confident' => ':name — mokėtina :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'padidėjo',
        'down' => 'sumažėjo',
    ],

    'digest' => [
        'nothing_notable' => 'Tavo dėmesio niekas nereikalauja.',
        'flow' => 'Gauta :in, išleista :out, grynasis :net.',
        'net_worth' => 'Grynoji vertė :amount.',
        'over_budget' => 'Iki šiol biudžetas viršytas :amount.',
        'payments_due' => 'Šį laikotarpį mokėtinas :count mokėjimas.|Šį laikotarpį mokėtini :count mokėjimai.|Šį laikotarpį mokėtina :count mokėjimų.',
        'shortfall' => 'Artėja lėšų trūkumas.',
        'forecast_not_run' => 'Pinigų srautų prognozė dar nebuvo atlikta.',
    ],
];
