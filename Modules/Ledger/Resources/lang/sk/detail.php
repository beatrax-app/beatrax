<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcia',
    'heading' => 'Transakcia',

    'counterparty' => 'Protistrana',
    'amount_native' => 'Suma (pôvodná mena)',
    'amount_settled' => 'Suma (zúčtovaná v EUR)',
    'effective_rate' => 'Efektívny kurz',
    'ics_markup' => 'Zahŕňa prípadnú prirážku ICS.',

    'split' => [
        'category' => 'Kategória',
        'open' => 'Rozdeliť medzi kategórie',
        'heading' => 'Rozdelenie medzi kategórie',
        'total' => 'Spolu :amount',
        'tax_per_category' => 'Daňové značky sa nastavujú nižšie pre každú kategóriu zvlášť.',
        'choose_category' => 'Vyber kategóriu',
        'note_label' => 'Poznámka',
        'note_placeholder' => 'Poznámka (voliteľné)',
        'tax_deductible' => 'Daňovo uznateľné',
        'remove_leg_aria' => 'Odstrániť túto kategóriu',
        'add_category' => '+ Pridať kategóriu',
        'soft_cap' => ':count z ~20 kategórií — zváž zoskupenie malých súm.',
        'remaining_zero' => 'Zostáva :amount ✓',
        'remaining_to_assign' => 'Zostáva priradiť: :amount',
        'over_allocated' => 'Prekročené o :amount — zníž niektorú časť.',
        'save' => 'Uložiť rozdelenie',
        'saving' => 'Ukladá sa…',
        'unsplit' => 'Zrušiť rozdelenie transakcie',
        'remove_to_one' => 'Po odstránení zostane jedna kategória — transakcia bude patriť sem: :category.',
        'remove_to_one_fallback' => 'táto kategória',
        'remove_category' => 'Odstrániť kategóriu',
        'keep_category' => 'Ponechať túto kategóriu',
        'restore_single' => 'Obnoviť ako jednu kategóriu?',
        'confirm_unsplit' => 'Áno, zrušiť rozdelenie',
        'keep_split' => 'Ponechať rozdelenie',
    ],

    'tax' => [
        'section_aria' => 'Daňová značka',
        'label' => 'Daňovo uznateľné',
    ],

    'reclassify' => [
        'heading' => 'Preklasifikovať',
        'help' => 'Prepíš zistený typ. Ak je táto transakcia spárovaná s inou, výberom iného typu než prevod sa párovanie na oboch stranách zruší.',
        'choose_aria' => 'Vyber nový typ transakcie',
        'choose_option' => 'Vyber typ…',
        'save' => 'Uložiť',
    ],

    'type_label' => [
        'expense' => 'Výdavok',
        'income' => 'Príjem',
        'transfer_out' => 'Odchádzajúci prevod',
        'transfer_in' => 'Prichádzajúci prevod',
        'fee' => 'Poplatok',
        'refund' => 'Vrátenie peňazí',
        'adjustment' => 'Úprava',
    ],

    'note' => [
        'heading' => 'Poznámka',
        'help' => 'Osobná poznámka k tejto transakcii. Vidíš ju len ty.',
        'label' => 'Poznámka',
        'placeholder' => 'Pridaj poznámku…',
        'save' => 'Uložiť poznámku',
        'saved' => 'Uložené',
    ],

    'reassign' => [
        'heading' => 'Zmeniť protistranu',
        'help' => 'Prepíš rozpoznanú protistranu tejto transakcie.',
        'choose_aria' => 'Vyber protistranu',
        'choose_option' => 'Vyber protistranu…',
        'submit' => 'Zmeniť',
    ],

    'goal' => [
        'heading' => 'Sporiaci cieľ',
        'help' => 'Započítaj túto transakciu do niektorého zo svojich sporiacich cieľov.',
        'choose_aria' => 'Vyber sporiaci cieľ',
        'choose_option' => 'Vyber cieľ…',
        'submit' => 'Pridať k cieľu',
        'remove_aria' => 'Odstrániť :name',
    ],

    'delete' => [
        'heading' => 'Odstrániť transakciu',
        'help' => 'Natrvalo odstráni túto transakciu. Túto akciu nemožno vrátiť späť.',
        'button' => 'Odstrániť',
        'confirm_prompt' => 'Naozaj?',
        'confirm' => 'Áno, odstrániť',
        'cancel' => 'Zrušiť',
    ],

    'chain' => [
        'view' => 'Zobraziť reťazec',
    ],

    'toast' => [
        'reconciled_locked' => 'Táto transakcia je odsúhlasená. Ak ju chceš zmeniť, najprv zruš odsúhlasenie.',
        'reclassified_pair_removed' => 'Preklasifikované na :type — párovanie zrušené',
        'reclassified' => 'Preklasifikované na :type',
        'note_saved' => 'Poznámka uložená',
        'unreconciled' => 'Odsúhlasenie zrušené — transakciu môžeš znova upravovať.',
        'counterparty_updated' => 'Protistrana aktualizovaná',
        'goal_attributed' => 'Započítané do tohto cieľa',
        'goal_attribution_removed' => 'Už sa do tohto cieľa nezapočítava',
        'split_saved' => 'Rozdelenie uložené',
        'removed_one_remains' => 'Odstránené — zostáva jedna kategória',
        'unsplit_restored' => 'Rozdelenie zrušené — obnovené na jednu kategóriu',
    ],

    'errors' => [
        'totals_must_match' => 'Nepodarilo sa uložiť — súčet častí musí presne zodpovedať celkovej sume transakcie.',
        'not_found' => 'Transakcia sa nenašla.',
        'amount_zero' => 'Suma nemôže byť 0,00 €',
        'choose_category' => 'Vyber kategóriu.',
        'choose_before_removing' => 'Pred odstránením vyber kategóriu.',
        'choose_before_unsplitting' => 'Pred zrušením rozdelenia vyber kategóriu.',
        'not_found_or_unowned' => 'Transakcia sa nenašla alebo nepatrí používateľovi.',
        'reconciled_split' => 'Táto transakcia je odsúhlasená. Ak chceš zmeniť jej rozdelenie, najprv zruš odsúhlasenie.',
        'not_splittable' => 'Typ transakcie „:type“ sa nedá rozdeliť.',
        'min_two_legs' => 'Rozdelenie vyžaduje aspoň 2 časti.',
        'legs_non_zero' => 'Sumy častí nesmú byť nulové.',
        'legs_parent_sign' => 'Sumy častí musia mať rovnaké znamienko ako pôvodná transakcia.',
        'leg_category_not_accessible' => 'Kategória časti sa nenašla alebo nie je pre používateľa prístupná.',
        'survivor_not_accessible' => 'Zostávajúca kategória sa nenašla alebo nie je pre používateľa prístupná.',
        'survivor_must_be_current' => 'Zostávajúca kategória musí byť jednou z aktuálnych kategórií rozdelenia.',
    ],
];
