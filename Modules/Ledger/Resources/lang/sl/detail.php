<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcija',
    'heading' => 'Transakcija',

    'counterparty' => 'Nasprotna stranka',
    'amount_native' => 'Znesek (izvirna valuta)',
    'amount_settled' => 'Znesek (obračunano v EUR)',
    'effective_rate' => 'Efektivni tečaj',
    'ics_markup' => 'Vključuje morebitno maržo ICS.',

    'split' => [
        'category' => 'Kategorija',
        'open' => 'Razdeli po kategorijah',
        'heading' => 'Razdelitev po kategorijah',
        'total' => 'Skupaj :amount',
        'tax_per_category' => 'Davčne oznake se spodaj nastavijo za vsako kategorijo posebej.',
        'choose_category' => 'Izberi kategorijo',
        'note_label' => 'Opomba',
        'note_placeholder' => 'Opomba (neobvezno)',
        'tax_deductible' => 'Davčno priznano',
        'remove_leg_aria' => 'Odstrani to kategorijo',
        'add_category' => '+ Dodaj kategorijo',
        'soft_cap' => ':count od ~20 kategorij — razmisli o združevanju majhnih zneskov.',
        'remaining_zero' => 'Preostalo :amount ✓',
        'remaining_to_assign' => 'Preostalo za razporeditev: :amount',
        'over_allocated' => 'Razporejeno je :amount preveč — zmanjšaj eno postavko.',
        'save' => 'Shrani razdelitev',
        'saving' => 'Shranjevanje…',
        'unsplit' => 'Razveljavi razdelitev transakcije',
        'remove_to_one' => 'Po odstranitvi ostane ena kategorija — transakcija postane :category.',
        'remove_to_one_fallback' => 'ta kategorija',
        'remove_category' => 'Odstrani kategorijo',
        'keep_category' => 'Obdrži to kategorijo',
        'restore_single' => 'Obnoviti kot eno samo kategorijo?',
        'confirm_unsplit' => 'Da, razveljavi razdelitev',
        'keep_split' => 'Obdrži razdelitev',
    ],

    'tax' => [
        'section_aria' => 'Davčna oznaka',
        'label' => 'Davčno priznano',
    ],

    'reclassify' => [
        'heading' => 'Spremeni razvrstitev',
        'help' => 'Preglasi zaznano vrsto. Če je ta transakcija seznanjena z drugo, bo izbira vrste, ki ni prenos, razdružila obe strani.',
        'choose_aria' => 'Izberi novo vrsto transakcije',
        'choose_option' => 'Izberi vrsto…',
        'save' => 'Shrani',
    ],

    'note' => [
        'heading' => 'Opomba',
        'help' => 'Osebna opomba za to transakcijo. Vidna je samo tebi.',
        'label' => 'Opomba',
        'placeholder' => 'Dodaj opombo…',
        'save' => 'Shrani opombo',
        'saved' => 'Shranjeno',
    ],

    'reassign' => [
        'heading' => 'Zamenjaj nasprotno stranko',
        'help' => 'Preglasi prepoznano nasprotno stranko za to transakcijo.',
        'choose_aria' => 'Izberi nasprotno stranko',
        'choose_option' => 'Izberi nasprotno stranko…',
        'submit' => 'Zamenjaj',
    ],

    'delete' => [
        'heading' => 'Izbriši transakcijo',
        'help' => 'Trajno odstrani to transakcijo. Tega dejanja ni mogoče razveljaviti.',
        'button' => 'Izbriši',
        'confirm_prompt' => 'Si prepričan?',
        'confirm' => 'Da, izbriši',
        'cancel' => 'Prekliči',
    ],

    'chain' => [
        'view' => 'Prikaži verigo',
    ],

    'toast' => [
        'reconciled_locked' => 'Ta transakcija je usklajena. Razveljavi uskladitev, da narediš spremembe.',
        'reclassified_pair_removed' => 'Prerazvrščeno v :type — seznanitev odstranjena',
        'reclassified' => 'Prerazvrščeno v :type',
        'note_saved' => 'Opomba shranjena',
        'unreconciled' => 'Uskladitev razveljavljena — to transakcijo lahko znova urejaš.',
        'counterparty_updated' => 'Nasprotna stranka posodobljena',
        'split_saved' => 'Razdelitev shranjena',
        'removed_one_remains' => 'Odstranjeno — ostane ena kategorija',
        'unsplit_restored' => 'Razdelitev razveljavljena — obnovljeno na eno kategorijo',
    ],

    'errors' => [
        'totals_must_match' => 'Shranjevanje ni uspelo — vsota postavk se mora natančno ujemati s skupnim zneskom transakcije.',
        'not_found' => 'Transakcija ni najdena.',
        'amount_zero' => 'Znesek ne more biti €0,00',
        'choose_category' => 'Izberi kategorijo.',
        'choose_before_removing' => 'Pred odstranitvijo izberi kategorijo.',
        'choose_before_unsplitting' => 'Pred razveljavitvijo razdelitve izberi kategorijo.',
        'not_found_or_unowned' => 'Transakcija ni najdena ali ni v lasti uporabnika.',
        'reconciled_split' => 'Ta transakcija je usklajena. Razveljavi uskladitev, da spremeniš njeno razdelitev.',
        'not_splittable' => 'Vrste transakcije „:type“ ni mogoče razdeliti.',
        'min_two_legs' => 'Razdelitev zahteva vsaj 2 postavki.',
        'legs_non_zero' => 'Zneski postavk ne smejo biti nič.',
        'legs_parent_sign' => 'Zneski postavk morajo imeti enak predznak kot nadrejena transakcija.',
        'leg_category_not_accessible' => 'Kategorija postavke ni najdena ali uporabnik nima dostopa.',
        'survivor_not_accessible' => 'Preostala kategorija ni najdena ali uporabnik nima dostopa.',
        'survivor_must_be_current' => 'Preostala kategorija mora biti ena od trenutnih kategorij postavk v razdelitvi.',
    ],
];
