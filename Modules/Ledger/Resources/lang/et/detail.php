<?php

declare(strict_types=1);

return [
    'page_title' => 'Tehing',
    'heading' => 'Tehing',

    'counterparty' => 'Vastaspool',
    'amount_native' => 'Summa (algne)',
    'amount_settled' => 'Summa (arveldatud EUR)',
    'effective_rate' => 'Tegelik kurss',
    'ics_markup' => 'Sisaldab võimalikku ICS juurdehindlust.',

    'split' => [
        'category' => 'Kategooria',
        'open' => 'Jaga kategooriate vahel',
        'heading' => 'Jaga kategooriate vahel',
        'total' => 'Kokku :amount',
        'tax_per_category' => 'Maksumärgendid määratakse allpool kategooriate kaupa.',
        'choose_category' => 'Vali kategooria',
        'note_label' => 'Märkus',
        'note_placeholder' => 'Märkus (valikuline)',
        'tax_deductible' => 'Maksust mahaarvatav',
        'remove_leg_aria' => 'Eemalda see kategooria',
        'add_category' => '+ Lisa kategooria',
        'soft_cap' => ':count kategooriat ~20-st — kaalu väikeste summade koondamist.',
        'remaining_zero' => 'Jääk :amount ✓',
        'remaining_to_assign' => 'Jagamata: :amount',
        'over_allocated' => 'Üle jagatud :amount võrra — vähenda mõnda osa.',
        'save' => 'Salvesta jaotus',
        'saving' => 'Salvestan…',
        'unsplit' => 'Tühista tehingu jaotus',
        'remove_to_one' => 'Selle eemaldamisel jääb alles üks kategooria — tehinguks saab :category.',
        'remove_to_one_fallback' => 'see kategooria',
        'remove_category' => 'Eemalda kategooria',
        'keep_category' => 'Jäta see kategooria alles',
        'restore_single' => 'Kas taastada ühe kategooriaga?',
        'survivor_legend' => 'Kategooria, mis jääb alles',
        'confirm_unsplit' => 'Jah, tühista jaotus',
        'keep_split' => 'Jäta jaotus alles',
    ],

    'tax' => [
        'section_aria' => 'Maksumärgend',
        'label' => 'Maksust mahaarvatav',
    ],

    'reclassify' => [
        'heading' => 'Liigita ümber',
        'help' => 'Muuda tuvastatud tüüpi. Kui see tehing on teisega paaris, siis mitte-ülekande tüübi valimine lahutab mõlemad pooled.',
        'choose_aria' => 'Vali tehingu uus tüüp',
        'choose_option' => 'Vali tüüp…',
        'save' => 'Salvesta',
    ],

    'type_label' => [
        'expense' => 'Kulu',
        'income' => 'Tulu',
        'transfer_out' => 'Väljaminev ülekanne',
        'transfer_in' => 'Sissetulev ülekanne',
        'fee' => 'Teenustasu',
        'refund' => 'Tagasimakse',
        'adjustment' => 'Korrigeerimine',
    ],

    'note' => [
        'heading' => 'Märkus',
        'help' => 'Isiklik märkus selle tehingu kohta. Näed seda ainult sina.',
        'label' => 'Märkus',
        'placeholder' => 'Lisa märkus…',
        'save' => 'Salvesta märkus',
        'saved' => 'Salvestatud',
    ],

    'reassign' => [
        'heading' => 'Määra uus vastaspool',
        'help' => 'Muuda selle tehingu jaoks tuvastatud vastaspoolt.',
        'choose_aria' => 'Vali vastaspool',
        'choose_option' => 'Vali vastaspool…',
        'submit' => 'Määra uuesti',
    ],

    'goal' => [
        'heading' => 'Säästueesmärk',
        'help' => 'Arvesta see tehing mõne oma säästueesmärgi hulka.',
        'choose_aria' => 'Vali säästueesmärk',
        'choose_option' => 'Vali eesmärk…',
        'submit' => 'Lisa eesmärgile',
        'remove_aria' => 'Eemalda :name',
    ],

    'delete' => [
        'heading' => 'Kustuta tehing',
        'help' => 'Eemaldab selle tehingu jäädavalt. Seda toimingut ei saa tagasi võtta.',
        'button' => 'Kustuta',
        'confirm_prompt' => 'Kas oled kindel?',
        'confirm' => 'Jah, kustuta',
        'cancel' => 'Tühista',
    ],

    'chain' => [
        'view' => 'Vaata ahelat',
    ],

    'toast' => [
        'reconciled_locked' => 'See tehing on kooskõlastatud. Muudatuste tegemiseks tühista kooskõlastus.',
        'reclassified_pair_removed' => 'Ümber liigitatud tüübiks :type — paar eemaldatud',
        'reclassified' => 'Ümber liigitatud tüübiks :type',
        'note_saved' => 'Märkus salvestatud',
        'unreconciled' => 'Kooskõlastus tühistatud — saad seda tehingut jälle muuta.',
        'counterparty_updated' => 'Vastaspool uuendatud',
        'goal_attributed' => 'Arvestatakse selle eesmärgi hulka',
        'goal_attribution_removed' => 'Enam ei arvestata selle eesmärgi hulka',
        'split_saved' => 'Jaotus salvestatud',
        'removed_one_remains' => 'Eemaldatud — alles jäi üks kategooria',
        'unsplit_restored' => 'Jaotus tühistatud — taastatud ühe kategooriaga',
    ],

    'errors' => [
        'totals_must_match' => 'Ei õnnestunud salvestada — osade summad peavad tehingu kogusummaga täpselt kokku langema.',
        'not_found' => 'Tehingut ei leitud.',
        'amount_zero' => 'Summa ei saa olla :amount',
        'choose_category' => 'Vali kategooria.',
        'choose_before_removing' => 'Vali enne eemaldamist kategooria.',
        'choose_before_unsplitting' => 'Vali enne jaotuse tühistamist kategooria.',
        'not_found_or_unowned' => 'Tehingut ei leitud või see ei kuulu kasutajale.',
        'reconciled_split' => 'See tehing on kooskõlastatud. Jaotuse muutmiseks tühista kooskõlastus.',
        'not_splittable' => 'Tehingu tüüpi „:type“ ei saa jagada.',
        'min_two_legs' => 'Jaotus vajab vähemalt 2 osa.',
        'legs_non_zero' => 'Osade summad ei tohi olla nullid.',
        'legs_parent_sign' => 'Osade summadel peab olema ülemtehinguga sama märk.',
        'leg_category_not_accessible' => 'Osa kategooriat ei leitud või pole see kasutajale kättesaadav.',
        'survivor_not_accessible' => 'Alles jäävat kategooriat ei leitud või pole see kasutajale kättesaadav.',
        'survivor_must_be_current' => 'Alles jääv kategooria peab olema üks jaotuse praegustest osade kategooriatest.',
    ],
];
