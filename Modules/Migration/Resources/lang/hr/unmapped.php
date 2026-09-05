<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cilj: :name',
        'category_goal' => 'Cilj kategorije :name',
        'schedule_untitled' => 'Zakazana transakcija bez naziva',
        'transaction' => 'Transakcija: :name · :date · :amount',
        'transaction_unnamed' => 'Transakcija',
        'amount_update' => 'Ažuriranje iznosa transakcije',
        'budget_history' => 'Povijest proračuna u :currency',
        'budget_file_currency' => 'Valuta datoteke proračuna',
        'budget_file_mode' => 'Način rada datoteke proračuna',
    ],

    'conflict' => [
        'budget_assignment' => 'Raspoređivanje proračuna',
        'budget_for_month' => 'Proračun: :category · :month',
        'budget_for_category' => 'Proračun: :category',
        'category_name' => 'Naziv kategorije',
        'category_name_of' => 'Naziv kategorije „:name”',
        'account_name' => 'Naziv računa',
        'account_name_of' => 'Naziv računa „:name”',
        'transaction_amount' => 'Iznos transakcije',
        'transaction_amount_of' => 'Iznos: :name',
        'transaction_amount_of_dated' => 'Iznos: :name · :date',
        'transaction_description' => 'Opis transakcije',
        'transaction_description_of' => 'Opis: :name',
        'transaction_description_of_dated' => 'Opis: :name · :date',
        'other' => 'Uvezena vrijednost',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ova se transakcija sudarila s drugom već zabilježenom transakcijom (isti otisak) i nije uvezena.',
        'reconciled_status_kept' => 'Status usklađenosti iz izvora nije se mogao primijeniti — ova je transakcija usklađena u Beatraxu i to mijenja samo poništenje usklađivanja. Ostavljeno nepromijenjeno.',

        // i18n-review: hr · reason.split_legs_without_category — the waiting
        // bucket reads „u kategoriji Bez kategorije”, repeating this locale's
        // own name for Uncategorized. A bare „čeka u :uncategorized” is
        // ungrammatical, so the repetition is what correctness costs here.
        'split_legs_without_category' => ':count stavka podjele od :legs nema kategoriju, a stavka se bez nje ne može spremiti. Transakcija je uvezena u punom iznosu i čeka u kategoriji :uncategorized.|:count stavke podjele od :legs nemaju kategoriju, a stavka se bez nje ne može spremiti. Transakcija je uvezena u punom iznosu i čeka u kategoriji :uncategorized.|:count stavki podjele od :legs nema kategoriju, a stavka se bez nje ne može spremiti. Transakcija je uvezena u punom iznosu i čeka u kategoriji :uncategorized.',
        'split_sum_mismatch' => 'Stavke podjele zbrajaju se na :legs, a transakcija je :total, dok se podjela mora točno poklapati sa svojom transakcijom. Transakcija je uvezena u punom iznosu, bez svojih stavki.',
        'split_unstorable' => 'Beatrax ne može spremiti ovu podjelu u ovakvom obliku, pa je transakcija uvezena sama, bez svojih stavki.',
        'goal_without_target_date' => 'Ovaj cilj nema ciljni datum; Beatrax ga zahtijeva za stvaranje cilja štednje.',
        'goal_without_name' => 'Ovaj cilj nema naziv; Beatrax ga zahtijeva za stvaranje cilja štednje.',
        'goal_def_unsupported' => 'categories.goal_def koristi nepodržan (neravan) oblik predloška — cilj nije uvezen.',
        'budget_currency_mismatch' => ':count redak proračuna nije uvezen: tvoji se proračuni vode u :envelope, a ovaj izvoz proračunava u :source.|:count retka proračuna nisu uvezena: tvoji se proračuni vode u :envelope, a ovaj izvoz proračunava u :source.|:count redaka proračuna nije uvezeno: tvoji se proračuni vode u :envelope, a ovaj izvoz proračunava u :source.',
        'amount_apply_collision' => 'Novi iznos iz izvora nije se mogao primijeniti — sudara se s otiskom druge transakcije (isti račun, datum, valuta i protustranka). Ostavljeno nepromijenjeno.',
        'amount_currency_mismatch' => 'Iznosi transakcija nisu usklađeni: te se transakcije vode u :local, a ovaj ih izvoz navodi u :source. Ostavljeni nepromijenjeni.',
        'schedule_unsupported' => 'Zakazane i ponavljajuće transakcije još nemaju u Beatraxu put za stvaranje iz vanjskog izvora — sačuvane su samo kao bilješka, ne kao živa ponavljajuća serija.',
        'saved_report_unsupported' => 'Spremljena izvješća i konfiguracije analiza nemaju u Beatraxu istovrijednicu.',
        'assumed_currency' => "Pretpostavljeno :currency — u ovom izvozu nije pronađen redak 'preferences.currencyCode'.",
        'assumed_budget_type' => "Pretpostavljeno :mode — u ovom izvozu nije pronađen redak 'preferences.budgetType'.",
        'changed_on_both_sides' => "I izvorna datoteka i Beatrax promijenili su ovo od zadnjeg uvoza.\nLokalno: :local\nIzvor: :source\nZadnji uvoz: :baseline",
        'take_source' => 'Vrijednost novog izvoza primijenit će se kad potvrdiš — tvoja lokalna vrijednost bit će zamijenjena.',
        'keep_local' => 'Tvoja lokalna vrijednost bit će zadržana — vrijednost novog izvoza neće se primijeniti.',
        'compared_values' => ":intro\nLokalno: :local · Izvor: :source · Zadnji uvoz: :baseline",
    ],

    'value' => [
        'none' => '(nema)',
        'quoted' => '„:value”',
    ],
];
