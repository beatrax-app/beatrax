<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Typ protistrany: :type',
        'merchant' => 'Obchodník',
        'personal' => 'Súkromná osoba',
        'bank' => 'Banka',
        'government' => 'Štátna inštitúcia',
        'self' => 'Vlastné',
        'unknown' => 'Neznáma',
    ],

    'filter_chips' => [
        'aria' => 'Filtrovať podľa typu',
        'all' => 'Všetky',
        'merchant' => 'Obchodníci',
        'personal' => 'Súkromné osoby',
        'bank' => 'Banky',
        'government' => 'Štátne inštitúcie',
        'self' => 'Vlastné',
        'unknown' => 'Neznáme',
    ],

    'default_name' => [
        'bank_fee' => 'Bankový poplatok',
    ],

    'cp_card' => [
        'aria' => 'Protistrana: :name',
        'recent_aria' => 'Nedávna aktivita',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finančný reťazec: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je skrytý — zobrazíš ho kliknutím na Zobraziť IBAN',
        // i18n-review: sk · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN je skrytý — zobrazíš ho ťuknutím na Zobraziť IBAN',
        'show' => 'Zobraziť IBAN',
        'hide' => 'Skryť IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Upozornenie o súkromí pri osobnom kontakte',
        'body' => '🔒 Toto je osobný kontakt. IBAN a osobné údaje sú predvolene skryté a nikdy sa nedostanú do exportov.',
    ],

    'self_stub' => [
        'aria' => 'Nie je to skutočná protistrana',
        'heading' => 'V skutočnosti to nie je protistrana',

        'body_rest_html' => ' sa tu zobrazuje, pretože v transakciách vystupuje ako finančný úsek medzi účtami. Je to však <strong>tvoj vlastný účet</strong>, nie niekto, s kým obchoduješ.',
        'body2' => 'Otvor zobrazenie účtu — nájdeš tam zostatok, výpisy z účtu a celú históriu transakcií.',
        'open_cta' => 'Otvoriť zobrazenie účtu: :name →',
        'hide_cta' => 'Skryť z tohto zoznamu',
        'recent_legs' => 'Nedávne úseky medzi účtami',
    ],
];
