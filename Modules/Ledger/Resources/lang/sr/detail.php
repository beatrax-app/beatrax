<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcija',
    'heading' => 'Transakcija',
    'booked_on' => 'Knjiženo :date',

    'counterparty' => 'Druga strana',
    'description' => 'Opis',
    'amount_native' => 'Iznos (izvorna valuta)',
    'amount_settled' => 'Iznos (poravnat)',
    'effective_rate' => 'Efektivni kurs',
    'ics_markup' => 'Uključuje eventualnu ICS maržu.',

    'split' => [
        'category' => 'Kategorija',
        'open' => 'Podeli na kategorije',
        'heading' => 'Podela po kategorijama',
        'total' => 'Ukupno :amount',
        'tax_per_category' => 'Poreske oznake se postavljaju ispod za svaku kategoriju.',
        'choose_category' => 'Izaberi kategoriju',
        'note_label' => 'Beleška',
        'note_placeholder' => 'Beleška (opciono)',
        'tax_deductible' => 'Odbitno od poreza',
        'remove_leg_aria' => 'Ukloni ovu kategoriju',
        'remove_leg_caption' => 'Ukloni',
        'add_category' => '+ Dodaj kategoriju',
        'soft_cap' => ':count od ~20 kategorija — razmisli o grupisanju malih iznosa.',
        'remaining_zero' => 'Preostalo :amount ✓',
        'remaining_to_assign' => 'Preostalo za raspodelu: :amount',
        'over_allocated' => 'Raspoređeno je :amount previše — smanji jednu stavku.',
        'save' => 'Sačuvaj podelu',
        'saving' => 'Čuvanje…',
        'unsplit' => 'Poništi podelu transakcije',
        'remove_to_one' => 'Uklanjanjem ostaje jedna kategorija — transakcija postaje :category.',
        'remove_to_one_fallback' => 'ova kategorija',
        'remove_category' => 'Ukloni kategoriju',
        'keep_category' => 'Zadrži ovu kategoriju',
        'restore_single' => 'Vratiti na jednu kategoriju?',
        'survivor_legend' => 'Kategorija koja ostaje',
        'confirm_unsplit' => 'Da, poništi podelu',
        'keep_split' => 'Zadrži podelu',
    ],

    'tax' => [
        'section_aria' => 'Poreska oznaka',
        'label' => 'Odbitno od poreza',
    ],

    'reclassify' => [
        'heading' => 'Promeni klasifikaciju',
        'help' => 'Zameni otkriveni tip. Ako je ova transakcija uparena sa drugom, izbor tipa koji nije prenos razdvojiće obe strane.',
        'choose_aria' => 'Izaberi novi tip transakcije',
        'choose_option' => 'Izaberi tip…',
        'save' => 'Sačuvaj',
    ],

    'type_label' => [
        'expense' => 'Trošak',
        'income' => 'Prihod',
        'transfer_out' => 'Odlazni prenos',
        'transfer_in' => 'Dolazni prenos',
        'fee' => 'Naknada',
        'refund' => 'Povraćaj',
        'adjustment' => 'Korekcija',
    ],

    'note' => [
        'heading' => 'Beleška',
        'help' => 'Lična beleška za ovu transakciju. Vidljiva samo tebi.',
        'label' => 'Beleška',
        'placeholder' => 'Dodaj belešku…',
        'save' => 'Sačuvaj belešku',
        'saved' => 'Sačuvano',
    ],

    'reassign' => [
        'heading' => 'Promeni drugu stranu',
        'help' => 'Zameni prepoznatu drugu stranu za ovu transakciju.',
        'choose_aria' => 'Izaberi drugu stranu',
        'choose_option' => 'Izaberi drugu stranu…',
        'submit' => 'Promeni',
    ],

    'goal' => [
        'heading' => 'Cilj štednje',
        'help' => 'Uračunaj ovu transakciju u jedan od svojih ciljeva štednje.',
        'choose_aria' => 'Izaberi cilj štednje',
        'choose_option' => 'Izaberi cilj…',
        'submit' => 'Dodaj cilju',
        'remove_aria' => 'Ukloni :name',
    ],

    'delete' => [
        'heading' => 'Obriši transakciju',
        'help' => 'Trajno uklanja ovu transakciju. Ova radnja ne može da se opozove.',
        'button' => 'Obriši',
        'confirm_prompt' => 'Obrisati ovu transakciju? S njom nestaju beleška, podela i poreske oznake.',
        'confirm' => 'Da, obriši',
        'cancel' => 'Otkaži',
    ],

    'chain' => [
        'view' => 'Prikaži lanac',
    ],

    'unreconcile' => [
        'heading' => 'Usaglašeno i zaključano',
        'help' => 'Dovršeno usaglašavanje zaključalo je ovu transakciju. Njena kategorija, beleška, podela i poreske oznake ostaju kakve jesu dok je ne otključaš.',
        'button' => 'Otključaj za uređivanje',
        'confirm_question' => 'Otključati ovu transakciju za uređivanje? Na njoj se ništa ne menja, a sledeće dovršeno usaglašavanje je ponovo zaključava.',
        'cancel' => 'Ostavi je zaključanu',
    ],

    'toast' => [
        'reconciled_locked' => 'Ova transakcija je usaglašena. Poništi usaglašavanje da napraviš izmene.',
        'reclassified_pair_removed' => 'Preklasifikovano u :type — uparivanje uklonjeno',
        'reclassified' => 'Preklasifikovano u :type',
        'note_saved' => 'Beleška sačuvana',
        'unreconciled' => 'Usaglašavanje poništeno — možeš ponovo da uređuješ ovu transakciju.',
        'note_too_long' => 'Beleška ima najviše :max znak.|Beleška ima najviše :max znaka.|Beleška ima najviše :max znakova.',
        'counterparty_updated' => 'Druga strana ažurirana',
        'goal_attributed' => 'Uračunato u ovaj cilj',
        'goal_attribution_removed' => 'Više se ne uračunava u ovaj cilj',
        'split_saved' => 'Podela sačuvana',
        'removed_one_remains' => 'Uklonjeno — ostaje jedna kategorija',
        'unsplit_restored' => 'Podela poništena — vraćeno na jednu kategoriju',
    ],

    'errors' => [
        'totals_must_match' => 'Čuvanje nije uspelo — zbir stavki mora tačno da odgovara ukupnom iznosu transakcije.',
        'not_found' => 'Transakcija nije pronađena.',
        'amount_zero' => 'Iznos ne može biti :amount',
        'choose_category' => 'Izaberi kategoriju.',
        'choose_before_removing' => 'Izaberi kategoriju pre uklanjanja.',
        'choose_before_unsplitting' => 'Izaberi kategoriju pre poništavanja podele.',
        'not_found_or_unowned' => 'Transakcija nije pronađena ili nije u vlasništvu korisnika.',
        'reconciled_split' => 'Ova transakcija je usaglašena. Poništi usaglašavanje da promeniš njenu podelu.',
        'not_splittable' => 'Tip transakcije „:type” ne može da se podeli.',
        'min_two_legs' => 'Podela zahteva najmanje 2 stavke.',
        'legs_non_zero' => 'Iznosi stavki ne smeju biti nula.',
        'legs_parent_sign' => 'Iznosi stavki moraju imati isti predznak kao nadređena transakcija.',
        'leg_category_not_accessible' => 'Kategorija stavke nije pronađena ili korisnik nema pristup.',
        'survivor_not_accessible' => 'Preostala kategorija nije pronađena ili korisnik nema pristup.',
        'survivor_must_be_current' => 'Preostala kategorija mora biti jedna od trenutnih kategorija stavki u podeli.',
    ],
];
