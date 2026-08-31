<?php

declare(strict_types=1);

return [
    'page_title' => 'Kases grāmata',
    'heading' => 'Kases grāmata',
    'intro' => 'Pierakstiet skaidras naudas un citus ārpusbankas tēriņus manuāli. Manuālie ieraksti nonāk tajā pašā virsgrāmatā, kur importētie — tie tiek kategorizēti, sasaistīti ar darījuma partneri, iekļauti regulāro maksājumu atpazīšanā un ieskaitīti mēneša kopsummā.',

    'direction' => 'Virziens',
    'expense' => 'Izdevumi',
    'income' => 'Ieņēmumi',

    'amount' => 'Summa (:symbol)',
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
    'delete_entry_caption' => 'Dzēst',
    'delete' => 'Dzēst',
    'delete_confirm' => 'Dzēst šo ierakstu?',
    'delete_keep' => 'Paturēt',

    'errors' => [
        'amount_positive' => 'Ievadiet summu, kas lielāka par nulli.',
        'amount_too_large' => 'Šī summa ir pārāk liela. Pārbaudiet ciparus.',
        'amount_unreadable' => 'Summu nevarēja nolasīt. Ievadiet to ar ne vairāk kā :decimals cipariem aiz komata, piemēram :example.|Summu nevarēja nolasīt. Ievadiet to ar ne vairāk kā :decimals ciparu aiz komata, piemēram :example.|Summu nevarēja nolasīt. Ievadiet to ar ne vairāk kā :decimals cipariem aiz komata, piemēram :example.',
        'amount_unreadable_whole' => 'Summu nevarēja nolasīt. Šai valūtai nav decimāldaļu, tāpēc ievadiet veselu skaitli, piemēram :example.',
        'invalid_date' => 'Ievadiet derīgu datumu.',
        'not_recorded' => 'Ieraksts netika saglabāts. Mēģiniet to pievienot vēlreiz.',
    ],

    'toast' => [
        'added' => 'Skaidras naudas ieraksts pievienots.',
        'removed' => 'Skaidras naudas ieraksts noņemts.',
        'reconciled_locked' => 'Šis darījums ir saskaņots. Atceliet saskaņojumu, lai veiktu izmaiņas.',
    ],
];
