<?php

declare(strict_types=1);

return [
    'page_title' => 'Operacija',
    'heading' => 'Operacija',
    'booked_on' => 'Užregistruota :date',

    'counterparty' => 'Kita šalis',
    'description' => 'Aprašymas',
    'amount_native' => 'Suma (originali valiuta)',
    'amount_settled' => 'Suma (atsiskaityta)',
    'effective_rate' => 'Faktinis kursas',
    'ics_markup' => 'Įskaičiuotas ICS antkainis, jei jis taikytas.',

    'split' => [
        'category' => 'Kategorija',
        'open' => 'Padalyti į kategorijas',
        'heading' => 'Padalijimas tarp kategorijų',
        'total' => 'Iš viso :amount',
        'tax_per_category' => 'Mokesčių žymos nustatomos kiekvienai kategorijai atskirai žemiau.',
        'choose_category' => 'Pasirink kategoriją',
        'note_label' => 'Pastaba',
        'note_placeholder' => 'Pastaba (neprivaloma)',
        'tax_deductible' => 'Atskaitoma iš mokesčių',
        'remove_leg_aria' => 'Pašalinti šią kategoriją',
        'remove_leg_caption' => 'Pašalinti',
        'add_category' => '+ Pridėti kategoriją',
        'soft_cap' => ':count iš ~20 kategorijų — mažas sumas verta sugrupuoti.',
        'remaining_zero' => 'Liko :amount ✓',
        'remaining_to_assign' => 'Liko paskirstyti: :amount',
        'over_allocated' => 'Paskirstyta :amount per daug — sumažink vieną dalį.',
        'save' => 'Išsaugoti padalijimą',
        'saving' => 'Išsaugoma…',
        'unsplit' => 'Panaikinti operacijos padalijimą',
        'remove_to_one' => 'Pašalinus liks viena kategorija — operacija taps :category.',
        'remove_to_one_fallback' => 'ši kategorija',
        'remove_category' => 'Pašalinti kategoriją',
        'keep_category' => 'Palikti šią kategoriją',
        'restore_single' => 'Atkurti kaip vieną kategoriją?',
        'survivor_legend' => 'Kategorija, kurią palikti',
        'confirm_unsplit' => 'Taip, panaikinti padalijimą',
        'keep_split' => 'Palikti padalijimą',
    ],

    'tax' => [
        'section_aria' => 'Mokesčių žyma',
        'label' => 'Atskaitoma iš mokesčių',
    ],

    'reclassify' => [
        'heading' => 'Perklasifikuoti',
        'help' => 'Pakeisk aptiktą tipą. Jei ši operacija susieta su kita, pasirinkus ne pavedimo tipą, abiejų pusių susiejimas bus panaikintas.',
        'choose_aria' => 'Pasirink naują operacijos tipą',
        'choose_option' => 'Pasirink tipą…',
        'save' => 'Išsaugoti',
    ],

    'type_label' => [
        'expense' => 'Išlaidos',
        'income' => 'Pajamos',
        'transfer_out' => 'Išeinantis pervedimas',
        'transfer_in' => 'Gaunamas pervedimas',
        'fee' => 'Mokestis',
        'refund' => 'Grąžinimas',
        'adjustment' => 'Koregavimas',
    ],

    'note' => [
        'heading' => 'Pastaba',
        'help' => 'Asmeninė šios operacijos pastaba. Matoma tik tau.',
        'label' => 'Pastaba',
        'placeholder' => 'Pridėti pastabą…',
        'save' => 'Išsaugoti pastabą',
        'saved' => 'Išsaugota',
    ],

    'reassign' => [
        'heading' => 'Priskirti kitą šalį iš naujo',
        'help' => 'Pakeisk šiai operacijai nustatytą kitą šalį.',
        'choose_aria' => 'Pasirink kitą šalį',
        'choose_option' => 'Pasirink kitą šalį…',
        'submit' => 'Priskirti iš naujo',
    ],

    'goal' => [
        'heading' => 'Taupymo tikslas',
        'help' => 'Įskaityk šią operaciją į vieną iš savo taupymo tikslų.',
        'choose_aria' => 'Pasirink taupymo tikslą',
        'choose_option' => 'Pasirink tikslą…',
        'submit' => 'Pridėti prie tikslo',
        'remove_aria' => 'Pašalinti :name',
    ],

    'delete' => [
        'heading' => 'Ištrinti operaciją',
        'help' => 'Visam laikui pašalina šią operaciją. Šio veiksmo atšaukti negalima.',
        'button' => 'Ištrinti',
        'confirm_prompt' => 'Ištrinti šią operaciją? Kartu dings pastaba, skaidymas, mokesčių žymos ir bet koks įskaitymas į taupymo tikslą. Jei tai viena pavedimo pusė, kita pusė perklasifikuojama.',
        'confirm' => 'Taip, ištrinti',
        'cancel' => 'Atšaukti',
    ],

    'chain' => [
        'view' => 'Peržiūrėti grandinę',
    ],

    'unreconcile' => [
        'heading' => 'Suderinta ir užrakinta',
        'help' => 'Užbaigtas suderinimas užrakino šią operaciją. Jos kategorija, pastaba, skaidymas ir mokesčių žymos lieka tokios pačios, kol jos neatrakinsi.',
        'button' => 'Atrakinti redagavimui',
        'confirm_question' => 'Atrakinti šią operaciją redagavimui? Joje niekas nepasikeis, o kitas užbaigtas suderinimas ją vėl užrakins.',
        'cancel' => 'Palikti užrakintą',
    ],

    'toast' => [
        'reconciled_locked' => 'Ši operacija suderinta. Kad galėtum ją keisti, panaikink suderinimą.',
        'reclassified_pair_removed' => 'Perklasifikuota į :type — susiejimas pašalintas',
        'reclassified' => 'Perklasifikuota į :type',
        'note_saved' => 'Pastaba išsaugota',
        'unreconciled' => 'Suderinimas panaikintas — šią operaciją vėl gali redaguoti.',
        'note_too_long' => 'Pastaba yra ne daugiau kaip :max simbolis.|Pastaba yra ne daugiau kaip :max simboliai.|Pastaba yra ne daugiau kaip :max simbolių.',
        'counterparty_updated' => 'Kita šalis atnaujinta',
        'goal_attributed' => 'Įskaityta į šį tikslą',
        'goal_attribution_removed' => 'Nebeįskaitoma į šį tikslą',
        'split_saved' => 'Padalijimas išsaugotas',
        'removed_one_remains' => 'Pašalinta — liko viena kategorija',
        'unsplit_restored' => 'Padalijimas panaikintas — atkurta viena kategorija',
    ],

    'errors' => [
        'totals_must_match' => 'Nepavyko išsaugoti — dalių sumos turi tiksliai sutapti su visa operacijos suma.',
        'not_found' => 'Operacija nerasta.',
        'amount_zero' => 'Suma negali būti :amount',
        'choose_category' => 'Pasirink kategoriją.',
        'choose_before_removing' => 'Prieš pašalindamas pasirink kategoriją.',
        'choose_before_unsplitting' => 'Prieš panaikindamas padalijimą pasirink kategoriją.',
        'not_found_or_unowned' => 'Operacija nerasta arba nepriklauso naudotojui.',
        'reconciled_split' => 'Ši operacija suderinta. Kad pakeistum padalijimą, panaikink suderinimą.',
        'not_splittable' => 'Operacijos tipo „:type“ dalyti negalima.',
        'min_two_legs' => 'Padalijimui reikia bent 2 dalių.',
        'legs_non_zero' => 'Dalių sumos negali būti nulinės.',
        'legs_parent_sign' => 'Dalių sumų ženklas turi sutapti su pagrindinės operacijos ženklu.',
        'leg_category_not_accessible' => 'Dalies kategorija nerasta arba naudotojui neprieinama.',
        'survivor_not_accessible' => 'Liekančioji kategorija nerasta arba naudotojui neprieinama.',
        'survivor_must_be_current' => 'Liekančioji kategorija turi būti viena iš dabartinių padalijimo dalių kategorijų.',
    ],
];
