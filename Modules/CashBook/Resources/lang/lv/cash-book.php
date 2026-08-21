<?php

declare(strict_types=1);

return [
    'page_title' => 'Kases grāmata',
    'heading' => 'Kases grāmata',
    'intro' => 'Pierakstiet skaidras naudas un citus ārpusbankas tēriņus manuāli. Manuālie ieraksti nonāk tajā pašā virsgrāmatā, kur importētie — tie tiek kategorizēti, iekļauti regulāro maksājumu atpazīšanā un ieskaitīti mēneša kopsummā.',

    'direction' => 'Virziens',
    'expense' => 'Izdevumi',
    'income' => 'Ieņēmumi',

    'amount' => 'Summa (€)',
    'date' => 'Datums',
    'counterparty' => 'Darījuma partneris',
    'counterparty_placeholder' => 'piem. Maiznīca',
    'category' => 'Kategorija',
    'optional' => '(neobligāti)',
    'uncategorized' => 'Bez kategorijas',
    'note' => 'Piezīme',

    'add_entry' => 'Pievienot ierakstu',
    'manual_entries' => 'Manuālie ieraksti',
    'no_entries' => 'Vēl nav neviena manuāla ieraksta.',
    'delete_entry' => 'Dzēst ierakstu',
    'delete' => 'Dzēst',
    'delete_confirm' => 'Dzēst šo ierakstu?',
    'delete_keep' => 'Paturēt',

    'errors' => [
        'amount_positive' => 'Ievadiet summu, kas lielāka par nulli.',
        'amount_too_large' => 'Šī summa ir pārāk liela. Pārbaudiet ciparus.',
        'amount_unreadable' => 'Šo summu neizdevās nolasīt. Ievadiet to bez tūkstošu atdalītāja un ar ne vairāk kā divām zīmēm aiz komata, piemēram, :example.',
        'invalid_date' => 'Ievadiet derīgu datumu.',
    ],

    'toast' => [
        'added' => 'Skaidras naudas ieraksts pievienots.',
        'removed' => 'Skaidras naudas ieraksts noņemts.',
    ],
];
