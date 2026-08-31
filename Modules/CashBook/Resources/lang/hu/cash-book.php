<?php

declare(strict_types=1);

return [
    'page_title' => 'Pénztárkönyv',
    'heading' => 'Pénztárkönyv',
    'intro' => 'Rögzítsd kézzel a készpénzes és egyéb, bankon kívüli kiadásokat. A kézi tételek ugyanabba a nyilvántartásba kerülnek, mint az importált tételek — kategorizálhatók, partnerhez rendelhetők, részt vesznek az ismétlődések felismerésében, és beleszámítanak a hónapodba.',

    'direction' => 'Irány',
    'expense' => 'Kiadás',
    'income' => 'Bevétel',

    'amount' => 'Összeg (:symbol)',
    'date' => 'Dátum',
    'counterparty' => 'Partner',
    'counterparty_placeholder' => 'pl. Pékség',
    'category' => 'Kategória',
    'optional' => '(opcionális)',
    'uncategorized' => 'Kategorizálatlan',
    'note' => 'Megjegyzés',

    'add_entry' => 'Tétel hozzáadása',
    'manual_entries' => 'Kézi tételek',
    'no_entries' => 'Még nincs kézi tétel.',
    'delete_entry' => 'Tétel törlése',
    'delete_entry_caption' => 'Törlése',
    'delete' => 'Törlés',
    'delete_confirm' => 'Törli ezt a tételt?',
    'delete_keep' => 'Megtartás',

    'errors' => [
        'amount_positive' => 'Adj meg nullánál nagyobb összeget.',
        'amount_too_large' => 'Ez az összeg túl nagy. Ellenőrizd a számjegyeket.',
        'amount_unreadable' => 'Az összeget nem sikerült beolvasni. Add meg legfeljebb :decimals tizedesjeggyel, például :example.|Az összeget nem sikerült beolvasni. Add meg legfeljebb :decimals tizedesjeggyel, például :example.',
        'amount_unreadable_whole' => 'Az összeget nem sikerült beolvasni. Ennek a pénznemnek nincsenek tizedesjegyei, ezért adj meg egész számot, például :example.',
        'invalid_date' => 'Adj meg érvényes dátumot.',
        'not_recorded' => 'A tétel nem lett rögzítve. Próbáld meg újra hozzáadni.',
    ],

    'toast' => [
        'added' => 'Készpénzes tétel hozzáadva.',
        'removed' => 'Készpénzes tétel törölve.',
        'reconciled_locked' => 'Ez a tranzakció egyeztetve van. A módosításhoz szüntesd meg az egyeztetést.',
    ],
];
