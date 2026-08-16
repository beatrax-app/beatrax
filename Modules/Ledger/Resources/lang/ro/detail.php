<?php

declare(strict_types=1);

return [
    'page_title' => 'Tranzacție',
    'heading' => 'Tranzacție',

    'counterparty' => 'Contraparte',
    'amount_native' => 'Sumă (valuta originală)',
    'amount_settled' => 'Sumă (decontată în EUR)',
    'effective_rate' => 'Curs efectiv',
    'ics_markup' => 'Include eventualul adaos ICS.',

    'split' => [
        'category' => 'Categorie',
        'open' => 'Împarte pe categorii',
        'heading' => 'Împarte între categorii',
        'total' => 'Total :amount',
        'tax_per_category' => 'Etichetele fiscale se setează mai jos, pentru fiecare categorie.',
        'choose_category' => 'Alege o categorie',
        'note_label' => 'Notă',
        'note_placeholder' => 'Notă (opțional)',
        'tax_deductible' => 'Deductibil fiscal',
        'remove_leg_aria' => 'Elimină această categorie',
        'add_category' => '+ Adaugă categorie',
        'soft_cap' => ':count din ~20 de categorii — ia în calcul gruparea sumelor mici.',
        'remaining_zero' => 'Rămas :amount ✓',
        'remaining_to_assign' => 'Rămas de alocat: :amount',
        'over_allocated' => 'Alocat în plus cu :amount — redu o poziție.',
        'save' => 'Salvează împărțirea',
        'saving' => 'Se salvează…',
        'unsplit' => 'Anulează împărțirea tranzacției',
        'remove_to_one' => 'Dacă elimini asta, rămâne o singură categorie — tranzacția devine :category.',
        'remove_to_one_fallback' => 'această categorie',
        'remove_category' => 'Elimină categoria',
        'keep_category' => 'Păstrează această categorie',
        'restore_single' => 'Restaurezi ca o singură categorie?',
        'confirm_unsplit' => 'Da, anulează împărțirea',
        'keep_split' => 'Păstrează împărțirea',
    ],

    'tax' => [
        'section_aria' => 'Etichetă fiscală',
        'label' => 'Deductibil fiscal',
    ],

    'reclassify' => [
        'heading' => 'Reclasifică',
        'help' => 'Suprascrie tipul detectat. Dacă această tranzacție este împerecheată cu alta, alegerea unui tip diferit de transfer va desface împerecherea de ambele părți.',
        'choose_aria' => 'Alege noul tip de tranzacție',
        'choose_option' => 'Alege un tip…',
        'save' => 'Salvează',
    ],

    'note' => [
        'heading' => 'Notă',
        'help' => 'Notă personală pentru această tranzacție. Vizibilă doar pentru tine.',
        'label' => 'Notă',
        'placeholder' => 'Adaugă o notă…',
        'save' => 'Salvează nota',
        'saved' => 'Salvat',
    ],

    'reassign' => [
        'heading' => 'Reatribuie contrapartea',
        'help' => 'Suprascrie contrapartea identificată pentru această tranzacție.',
        'choose_aria' => 'Alege contrapartea',
        'choose_option' => 'Alege o contraparte…',
        'submit' => 'Reatribuie',
    ],

    'goal' => [
        'heading' => 'Obiectiv de economii',
        'help' => 'Contorizează această tranzacție într-unul dintre obiectivele tale de economii.',
        'choose_aria' => 'Alege un obiectiv de economii',
        'choose_option' => 'Alege un obiectiv…',
        'submit' => 'Adaugă la obiectiv',
        'remove_aria' => 'Elimină :name',
    ],

    'delete' => [
        'heading' => 'Șterge tranzacția',
        'help' => 'Elimină definitiv această tranzacție. Acțiunea nu poate fi anulată.',
        'button' => 'Șterge',
        'confirm_prompt' => 'Sigur?',
        'confirm' => 'Da, șterge',
        'cancel' => 'Anulează',
    ],

    'chain' => [
        'view' => 'Vezi lanțul',
    ],

    'toast' => [
        'reconciled_locked' => 'Această tranzacție este reconciliată. Anulează reconcilierea pentru a face modificări.',
        'reclassified_pair_removed' => 'Reclasificată ca :type — împerechere eliminată',
        'reclassified' => 'Reclasificată ca :type',
        'note_saved' => 'Notă salvată',
        'unreconciled' => 'Reconciliere anulată — poți edita din nou această tranzacție.',
        'counterparty_updated' => 'Contraparte actualizată',
        'goal_attributed' => 'Contorizată în acest obiectiv',
        'goal_attribution_removed' => 'Nu mai este contorizată în acest obiectiv',
        'split_saved' => 'Împărțire salvată',
        'removed_one_remains' => 'Eliminat — rămâne o singură categorie',
        'unsplit_restored' => 'Împărțire anulată — restaurată la o singură categorie',
    ],

    'errors' => [
        'totals_must_match' => 'Nu s-a putut salva — totalul pozițiilor trebuie să corespundă exact cu totalul tranzacției.',
        'not_found' => 'Tranzacția nu a fost găsită.',
        'amount_zero' => 'Suma nu poate fi €0,00',
        'choose_category' => 'Alege o categorie.',
        'choose_before_removing' => 'Alege o categorie înainte de eliminare.',
        'choose_before_unsplitting' => 'Alege o categorie înainte de a anula împărțirea.',
        'not_found_or_unowned' => 'Tranzacția nu a fost găsită sau nu aparține utilizatorului.',
        'reconciled_split' => 'Această tranzacție este reconciliată. Anulează reconcilierea pentru a-i schimba împărțirea.',
        'not_splittable' => "Tipul de tranzacție ':type' nu poate fi împărțit.",
        'min_two_legs' => 'O împărțire necesită cel puțin 2 poziții.',
        'legs_non_zero' => 'Sumele pozițiilor nu pot fi zero.',
        'legs_parent_sign' => 'Sumele pozițiilor trebuie să aibă același semn ca tranzacția-părinte.',
        'leg_category_not_accessible' => 'Categoria poziției nu a fost găsită sau nu este accesibilă utilizatorului.',
        'survivor_not_accessible' => 'Categoria rămasă nu a fost găsită sau nu este accesibilă utilizatorului.',
        'survivor_must_be_current' => 'Categoria rămasă trebuie să fie una dintre categoriile actuale ale pozițiilor din împărțire.',
    ],
];
