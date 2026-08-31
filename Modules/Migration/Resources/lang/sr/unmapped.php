<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cilj: :name',
        'category_goal' => 'Cilj za :name',
        'schedule_untitled' => 'Neimenovana zakazana transakcija',
        'transaction' => 'Transakcija: :name · :date · :amount',
        'transaction_unnamed' => 'Transakcija',
        'amount_update' => 'Ažuriranje iznosa transakcije',
        'budget_history' => 'Istorija budžeta u :currency',
        'budget_file_currency' => 'Valuta datoteke budžeta',
        'budget_file_mode' => 'Režim datoteke budžeta',
    ],

    'conflict' => [
        'budget_assignment' => 'Raspoređivanje budžeta',
        'budget_for_month' => 'Budžet: :category · :month',
        'budget_for_category' => 'Budžet: :category',
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
        'other' => 'Uvezena vrednost',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ova transakcija se poklopila sa drugom, već zabeleženom transakcijom (isti otisak) i nije uvezena.',
        'split_legs_without_category' => ':count stavka podele od :legs nema kategoriju, a stavka podele se bez nje ne može sačuvati. Transakcija je uvezena u punom iznosu i čeka u kategoriji „:uncategorized”.|:count stavke podele od :legs nemaju kategoriju, a stavka podele se bez nje ne može sačuvati. Transakcija je uvezena u punom iznosu i čeka u kategoriji „:uncategorized”.|:count stavki podele od :legs nema kategoriju, a stavka podele se bez nje ne može sačuvati. Transakcija je uvezena u punom iznosu i čeka u kategoriji „:uncategorized”.',
        'split_sum_mismatch' => 'Stavke podele daju zbir :legs, a transakcija je :total, dok podela mora tačno da odgovara svojoj transakciji. Transakcija je uvezena u punom iznosu, bez svojih stavki.',
        'split_unstorable' => 'Beatrax ne može da sačuva ovu podelu u ovakvom obliku, pa je transakcija uvezena sama, bez svojih stavki.',
        'goal_without_target_date' => 'Ovaj cilj nema ciljani datum; Beatraxu je potreban da bi napravio cilj štednje.',
        'goal_without_name' => 'Ovaj cilj nema naziv; Beatraxu je potreban da bi napravio cilj štednje.',
        'goal_def_unsupported' => 'categories.goal_def koristi nepodržan (neravan) oblik šablona — cilj nije uvezen.',
        'budget_currency_mismatch' => ':count red budžeta nije uvezen: tvoji budžeti se vode u :envelope, a ovaj izvoz budžet vodi u :source.|:count reda budžeta nisu uvezena: tvoji budžeti se vode u :envelope, a ovaj izvoz budžet vodi u :source.|:count redova budžeta nije uvezeno: tvoji budžeti se vode u :envelope, a ovaj izvoz budžet vodi u :source.',
        'amount_apply_collision' => 'Novi iznos iz izvora nije mogao da se primeni — sudara se sa otiskom druge transakcije (isti račun, datum, valuta i druga strana). Ostao je nepromenjen.',
        'schedule_unsupported' => 'Beatrax još ne ume da napravi zakazane i ponavljajuće transakcije iz spoljnog izvora — sačuvano samo kao beleška, ne kao aktivna serija u odeljku Ponavljajuće.',
        'saved_report_unsupported' => 'Sačuvani izveštaji i konfiguracije analiza nemaju ekvivalent u Beatraxu.',
        'assumed_currency' => "Pretpostavljeno: :currency — u ovom izvozu nije pronađen nijedan red 'preferences.currencyCode'.",
        'assumed_budget_type' => "Pretpostavljeno: :mode — u ovom izvozu nije pronađen nijedan red 'preferences.budgetType'.",
        'changed_on_both_sides' => "Od poslednjeg uvoza ovo su promenili i izvorna datoteka i Beatrax.\nLokalno: :local\nIzvor: :source\nPoslednji uvoz: :baseline",
        'take_source' => 'Vrednost iz novog izvoza biće primenjena kada potvrdiš — tvoja lokalna vrednost biće zamenjena.',
        'keep_local' => 'Tvoja lokalna vrednost biće zadržana — vrednost iz novog izvoza neće biti primenjena.',
        'compared_values' => ":intro\nLokalno: :local · Izvor: :source · Poslednji uvoz: :baseline",
    ],

    'value' => [
        'none' => '(nema)',
        'quoted' => '„:value”',
    ],
];
