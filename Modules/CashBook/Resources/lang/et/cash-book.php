<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassaraamat',
    'heading' => 'Kassaraamat',
    'intro' => 'Kirjuta sularaha ja muud pangaväline kulu käsitsi üles. Käsitsi lisatud kirjed jõuavad samasse pearaamatusse kui imporditud read — need kategoriseeritakse, seotakse vastaspoolega, kaasatakse korduvmaksete tuvastamisse ja arvestatakse sinu kuu hulka.',

    'direction' => 'Suund',
    'expense' => 'Kulu',
    'income' => 'Tulu',

    'amount' => 'Summa (:symbol)',
    'date' => 'Kuupäev',
    'counterparty' => 'Vastaspool',
    'counterparty_placeholder' => 'nt Pagariäri',
    'category' => 'Kategooria',
    'optional' => '(valikuline)',
    'uncategorized' => 'Kategoriseerimata',
    'note' => 'Märkus',

    'add_entry' => 'Lisa kirje',
    'manual_entries' => 'Käsitsi lisatud kirjed',
    'no_entries' => 'Käsitsi lisatud kirjeid veel pole.',
    'delete_entry' => 'Kustuta kirje',
    'delete_entry_caption' => 'Kustuta',
    'delete' => 'Kustuta',
    'delete_confirm' => 'Kas kustutada see kirje?',
    'delete_keep' => 'Säilita',

    'errors' => [
        'amount_positive' => 'Sisesta nullist suurem summa.',
        'amount_too_large' => 'See summa on liiga suur. Kontrolli numbreid.',
        'amount_unreadable' => 'Summat ei õnnestunud lugeda. Sisesta see kuni :decimals kümnendkohaga, näiteks :example.|Summat ei õnnestunud lugeda. Sisesta see kuni :decimals kümnendkohaga, näiteks :example.',
        'amount_unreadable_whole' => 'Summat ei õnnestunud lugeda. Sellel valuutal ei ole kümnendkohti, seega sisesta täisarv, näiteks :example.',
        'invalid_date' => 'Sisesta kehtiv kuupäev.',
        'not_recorded' => 'Kirjet ei salvestatud. Proovi see uuesti lisada.',
    ],

    'toast' => [
        'added' => 'Sularahakirje on lisatud.',
        'removed' => 'Sularahakirje on eemaldatud.',
        'reconciled_locked' => 'See tehing on kooskõlastatud. Muudatuste tegemiseks tühista kooskõlastus.',
    ],
];
