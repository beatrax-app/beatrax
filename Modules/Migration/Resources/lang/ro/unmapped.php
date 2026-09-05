<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Obiectiv: :name',
        'category_goal' => 'Obiectiv pentru :name',
        'schedule_untitled' => 'Tranzacție programată fără nume',
        'transaction' => 'Tranzacție: :name · :date · :amount',
        'transaction_unnamed' => 'Tranzacție',
        'amount_update' => 'Actualizare a sumei tranzacției',
        'budget_history' => 'Istoricul bugetului în :currency',
        'budget_file_currency' => 'Moneda fișierului de buget',
        'budget_file_mode' => 'Modul fișierului de buget',
    ],

    'conflict' => [
        'budget_assignment' => 'Alocare de buget',
        'budget_for_month' => 'Buget: :category · :month',
        'budget_for_category' => 'Buget: :category',
        'category_name' => 'Numele categoriei',
        'category_name_of' => 'Numele categoriei „:name”',
        'account_name' => 'Numele contului',
        'account_name_of' => 'Numele contului „:name”',
        'transaction_amount' => 'Suma tranzacției',
        'transaction_amount_of' => 'Sumă: :name',
        'transaction_amount_of_dated' => 'Sumă: :name · :date',
        'transaction_description' => 'Descrierea tranzacției',
        'transaction_description_of' => 'Descriere: :name',
        'transaction_description_of_dated' => 'Descriere: :name · :date',
        'other' => 'Valoare importată',
    ],

    'reason' => [
        'fingerprint_collision' => 'Această tranzacție s-a suprapus cu o altă tranzacție deja înregistrată (amprentă identică) și nu a fost importată.',
        'reconciled_status_kept' => 'Starea de reconciliere din sursă nu a putut fi aplicată — această tranzacție este reconciliată în Beatrax și doar anularea reconcilierii schimbă asta. Lăsată neschimbată.',
        'split_legs_without_category' => ':count poziție din :legs nu are categorie, iar o poziție nu poate fi salvată fără una. Tranzacția a fost importată cu suma întreagă și așteaptă în categoria :uncategorized.|:count poziții din :legs nu au categorie, iar o poziție nu poate fi salvată fără una. Tranzacția a fost importată cu suma întreagă și așteaptă în categoria :uncategorized.|:count de poziții din :legs nu au categorie, iar o poziție nu poate fi salvată fără una. Tranzacția a fost importată cu suma întreagă și așteaptă în categoria :uncategorized.',
        'split_sum_mismatch' => 'Pozițiile împărțirii însumează :legs, dar tranzacția este :total, iar o împărțire trebuie să corespundă exact tranzacției sale. Tranzacția a fost importată cu suma întreagă, fără pozițiile ei.',
        'split_unstorable' => 'Beatrax nu poate salva această împărțire așa cum este, așa că tranzacția a fost importată singură, fără pozițiile ei.',
        'goal_without_target_date' => 'Acest obiectiv nu are dată țintă; Beatrax are nevoie de una pentru a crea un obiectiv de economisire.',
        'goal_without_name' => 'Acest obiectiv nu are nume; Beatrax are nevoie de unul pentru a crea un obiectiv de economisire.',
        'goal_def_unsupported' => 'categories.goal_def folosește o formă de șablon neacceptată (neplată) — obiectivul nu a fost importat.',
        'budget_currency_mismatch' => ':count rând de buget nu a fost importat: bugetele tale sunt ținute în :envelope, iar acest export ține bugetul în :source.|:count rânduri de buget nu au fost importate: bugetele tale sunt ținute în :envelope, iar acest export ține bugetul în :source.|:count de rânduri de buget nu au fost importate: bugetele tale sunt ținute în :envelope, iar acest export ține bugetul în :source.',
        'amount_apply_collision' => 'Suma nouă din sursă nu a putut fi aplicată — se suprapune cu amprenta altei tranzacții (același cont, dată, monedă și contraparte). A rămas neschimbată.',
        'amount_currency_mismatch' => 'Sumele tranzacțiilor nu au fost reconciliate: aceste tranzacții sunt ținute în :local, iar acest export le indică în :source. Au rămas neschimbate.',
        'schedule_unsupported' => 'Beatrax nu poate încă să creeze tranzacții programate sau recurente dintr-o sursă externă — păstrate doar ca notă, nu ca serie activă în Recurente.',
        'saved_report_unsupported' => 'Rapoartele salvate și configurațiile de analiză nu au echivalent în Beatrax.',
        'assumed_currency' => "S-a presupus :currency — nu a fost găsit niciun rând 'preferences.currencyCode' în acest export.",
        'assumed_budget_type' => "S-a presupus :mode — nu a fost găsit niciun rând 'preferences.budgetType' în acest export.",
        'changed_on_both_sides' => "Atât fișierul-sursă, cât și Beatrax au schimbat asta de la ultimul import.\nLocal: :local\nSursă: :source\nUltimul import: :baseline",
        'take_source' => 'Valoarea din noul export se aplică atunci când confirmi — valoarea ta locală va fi înlocuită.',
        'keep_local' => 'Valoarea ta locală se păstrează — valoarea din noul export nu se aplică.',
        'compared_values' => ":intro\nLocal: :local · Sursă: :source · Ultimul import: :baseline",
    ],

    'value' => [
        'none' => '(niciuna)',
        'quoted' => '„:value”',
    ],
];
