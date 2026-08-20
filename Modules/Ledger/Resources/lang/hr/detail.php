<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcija',
    'heading' => 'Transakcija',

    'counterparty' => 'Protustranka',
    'amount_native' => 'Iznos (izvorna valuta)',
    'amount_settled' => 'Iznos (obračunato u EUR)',
    'effective_rate' => 'Efektivni tečaj',
    'ics_markup' => 'Uključuje eventualnu ICS maržu.',

    'split' => [
        'category' => 'Kategorija',
        'open' => 'Podijeli na kategorije',
        'heading' => 'Podjela po kategorijama',
        'total' => 'Ukupno :amount',
        'tax_per_category' => 'Porezne oznake postavljaju se niže za svaku kategoriju.',
        'choose_category' => 'Odaberi kategoriju',
        'note_label' => 'Bilješka',
        'note_placeholder' => 'Bilješka (neobavezno)',
        'tax_deductible' => 'Odbitno od poreza',
        'remove_leg_aria' => 'Ukloni ovu kategoriju',
        'add_category' => '+ Dodaj kategoriju',
        'soft_cap' => ':count od ~20 kategorija — razmisli o grupiranju malih iznosa.',
        'remaining_zero' => 'Preostalo :amount ✓',
        'remaining_to_assign' => 'Preostalo za raspodjelu: :amount',
        'over_allocated' => 'Raspoređeno je :amount previše — smanji jednu stavku.',
        'save' => 'Spremi podjelu',
        'saving' => 'Spremanje…',
        'unsplit' => 'Poništi podjelu transakcije',
        'remove_to_one' => 'Uklanjanjem ostaje jedna kategorija — transakcija postaje :category.',
        'remove_to_one_fallback' => 'ova kategorija',
        'remove_category' => 'Ukloni kategoriju',
        'keep_category' => 'Zadrži ovu kategoriju',
        'restore_single' => 'Vratiti na jednu kategoriju?',
        'survivor_legend' => 'Kategorija koja ostaje',
        'confirm_unsplit' => 'Da, poništi podjelu',
        'keep_split' => 'Zadrži podjelu',
    ],

    'tax' => [
        'section_aria' => 'Porezna oznaka',
        'label' => 'Odbitno od poreza',
    ],

    'reclassify' => [
        'heading' => 'Promijeni klasifikaciju',
        'help' => 'Zamijeni otkrivenu vrstu. Ako je ova transakcija uparena s drugom, odabir vrste koja nije prijenos razdvojit će obje strane.',
        'choose_aria' => 'Odaberi novu vrstu transakcije',
        'choose_option' => 'Odaberi vrstu…',
        'save' => 'Spremi',
    ],

    'type_label' => [
        'expense' => 'Trošak',
        'income' => 'Prihod',
        'transfer_out' => 'Odlazni prijenos',
        'transfer_in' => 'Dolazni prijenos',
        'fee' => 'Naknada',
        'refund' => 'Povrat',
        'adjustment' => 'Ispravak',
    ],

    'note' => [
        'heading' => 'Bilješka',
        'help' => 'Osobna bilješka za ovu transakciju. Vidljiva samo tebi.',
        'label' => 'Bilješka',
        'placeholder' => 'Dodaj bilješku…',
        'save' => 'Spremi bilješku',
        'saved' => 'Spremljeno',
    ],

    'reassign' => [
        'heading' => 'Promijeni protustranku',
        'help' => 'Zamijeni prepoznatu protustranku za ovu transakciju.',
        'choose_aria' => 'Odaberi protustranku',
        'choose_option' => 'Odaberi protustranku…',
        'submit' => 'Promijeni',
    ],

    'goal' => [
        'heading' => 'Cilj štednje',
        'help' => 'Uračunaj ovu transakciju u jedan od svojih ciljeva štednje.',
        'choose_aria' => 'Odaberi cilj štednje',
        'choose_option' => 'Odaberi cilj…',
        'submit' => 'Dodaj cilju',
        'remove_aria' => 'Ukloni :name',
    ],

    'delete' => [
        'heading' => 'Izbriši transakciju',
        'help' => 'Trajno uklanja ovu transakciju. Ova se radnja ne može poništiti.',
        'button' => 'Izbriši',
        'confirm_prompt' => 'Jesi li siguran?',
        'confirm' => 'Da, izbriši',
        'cancel' => 'Odustani',
    ],

    'chain' => [
        'view' => 'Prikaži lanac',
    ],

    'toast' => [
        'reconciled_locked' => 'Ova je transakcija usklađena. Poništi usklađivanje da napraviš promjene.',
        'reclassified_pair_removed' => 'Preklasificirano u :type — uparivanje uklonjeno',
        'reclassified' => 'Preklasificirano u :type',
        'note_saved' => 'Bilješka spremljena',
        'unreconciled' => 'Usklađivanje poništeno — možeš ponovno uređivati ovu transakciju.',
        'counterparty_updated' => 'Protustranka ažurirana',
        'goal_attributed' => 'Uračunato u ovaj cilj',
        'goal_attribution_removed' => 'Više se ne uračunava u ovaj cilj',
        'split_saved' => 'Podjela spremljena',
        'removed_one_remains' => 'Uklonjeno — ostaje jedna kategorija',
        'unsplit_restored' => 'Podjela poništena — vraćeno na jednu kategoriju',
    ],

    'errors' => [
        'totals_must_match' => 'Spremanje nije uspjelo — zbroj stavki mora točno odgovarati ukupnom iznosu transakcije.',
        'not_found' => 'Transakcija nije pronađena.',
        'amount_zero' => 'Iznos ne može biti €0,00',
        'choose_category' => 'Odaberi kategoriju.',
        'choose_before_removing' => 'Odaberi kategoriju prije uklanjanja.',
        'choose_before_unsplitting' => 'Odaberi kategoriju prije poništavanja podjele.',
        'not_found_or_unowned' => 'Transakcija nije pronađena ili nije u vlasništvu korisnika.',
        'reconciled_split' => 'Ova je transakcija usklađena. Poništi usklađivanje da promijeniš njezinu podjelu.',
        'not_splittable' => 'Vrsta transakcije „:type” ne može se podijeliti.',
        'min_two_legs' => 'Podjela zahtijeva najmanje 2 stavke.',
        'legs_non_zero' => 'Iznosi stavki ne smiju biti nula.',
        'legs_parent_sign' => 'Iznosi stavki moraju imati isti predznak kao nadređena transakcija.',
        'leg_category_not_accessible' => 'Kategorija stavke nije pronađena ili korisnik nema pristup.',
        'survivor_not_accessible' => 'Preostala kategorija nije pronađena ili korisnik nema pristup.',
        'survivor_must_be_current' => 'Preostala kategorija mora biti jedna od trenutnih kategorija stavki u podjeli.',
    ],
];
