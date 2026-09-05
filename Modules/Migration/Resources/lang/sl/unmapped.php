<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Cilj: :name',
        'category_goal' => 'Cilj za :name',
        'schedule_untitled' => 'Neimenovana načrtovana transakcija',
        'transaction' => 'Transakcija: :name · :date · :amount',
        'transaction_unnamed' => 'Transakcija',
        'amount_update' => 'Posodobitev zneska transakcije',
        'budget_history' => 'Zgodovina proračuna v :currency',
        'budget_file_currency' => 'Valuta proračunske datoteke',
        'budget_file_mode' => 'Način proračunske datoteke',
    ],

    'conflict' => [
        'budget_assignment' => 'Razporeditev proračuna',
        'budget_for_month' => 'Proračun: :category · :month',
        'budget_for_category' => 'Proračun: :category',
        'category_name' => 'Ime kategorije',
        'category_name_of' => 'Ime kategorije „:name“',
        'account_name' => 'Ime računa',
        'account_name_of' => 'Ime računa „:name“',
        'transaction_amount' => 'Znesek transakcije',
        'transaction_amount_of' => 'Znesek: :name',
        'transaction_amount_of_dated' => 'Znesek: :name · :date',
        'transaction_description' => 'Opis transakcije',
        'transaction_description_of' => 'Opis: :name',
        'transaction_description_of_dated' => 'Opis: :name · :date',
        'other' => 'Uvožena vrednost',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ta transakcija se je prekrivala z drugo, že zabeleženo transakcijo (enak prstni odtis) in ni bila uvožena.',

        // i18n-review: sl · reason.split_legs_without_category — the verb moves
        // with the noun across all four arms, so the dual reads "postavki ...
        // nimata". Grammar is checked against the rule table; whether "postavka
        // razdelitve" or a bare "postavka" reads better is the style call.
        'split_legs_without_category' => ':count postavka razdelitve od :legs nima kategorije, postavke razdelitve pa brez nje ni mogoče shraniti. Transakcija je bila uvožena v celotnem znesku in čaka v kategoriji „:uncategorized“.|:count postavki razdelitve od :legs nimata kategorije, postavke razdelitve pa brez nje ni mogoče shraniti. Transakcija je bila uvožena v celotnem znesku in čaka v kategoriji „:uncategorized“.|:count postavke razdelitve od :legs nimajo kategorije, postavke razdelitve pa brez nje ni mogoče shraniti. Transakcija je bila uvožena v celotnem znesku in čaka v kategoriji „:uncategorized“.|:count postavk razdelitve od :legs nima kategorije, postavke razdelitve pa brez nje ni mogoče shraniti. Transakcija je bila uvožena v celotnem znesku in čaka v kategoriji „:uncategorized“.',
        'split_sum_mismatch' => 'Postavke razdelitve se seštejejo v :legs, transakcija pa je :total, razdelitev se mora s svojo transakcijo ujemati natančno. Transakcija je bila uvožena v celotnem znesku, brez svojih postavk.',
        'split_unstorable' => 'Beatrax te razdelitve v takšni obliki ne more shraniti, zato je bila transakcija uvožena sama, brez svojih postavk.',
        'goal_without_target_date' => 'Ta cilj nima ciljnega datuma; Beatrax ga potrebuje za ustvarjanje varčevalnega cilja.',
        'goal_without_name' => 'Ta cilj nima imena; Beatrax ga potrebuje za ustvarjanje varčevalnega cilja.',
        'goal_def_unsupported' => 'categories.goal_def uporablja nepodprto (neploščato) obliko predloge — cilj ni bil uvožen.',
        'budget_currency_mismatch' => ':count vrstica proračuna ni bila uvožena: tvoji proračuni se vodijo v :envelope, ta izvoz pa proračun vodi v :source.|:count vrstici proračuna nista bili uvoženi: tvoji proračuni se vodijo v :envelope, ta izvoz pa proračun vodi v :source.|:count vrstice proračuna niso bile uvožene: tvoji proračuni se vodijo v :envelope, ta izvoz pa proračun vodi v :source.|:count vrstic proračuna ni bilo uvoženih: tvoji proračuni se vodijo v :envelope, ta izvoz pa proračun vodi v :source.',
        'amount_apply_collision' => 'Novega zneska iz vira ni bilo mogoče uporabiti — trči ob prstni odtis druge transakcije (isti račun, datum, valuta in nasprotna stranka). Ostal je nespremenjen.',
        'amount_currency_mismatch' => 'Zneski transakcij niso bili usklajeni: te transakcije se vodijo v :local, ta izvoz pa jih navaja v :source. Ostali so nespremenjeni.',
        'schedule_unsupported' => 'Beatrax načrtovanih in ponavljajočih se transakcij še ne zna ustvariti iz zunanjega vira — ohranjeno le kot opomba, ne kot dejavna serija v razdelku Ponavljajoče.',
        'saved_report_unsupported' => 'Shranjena poročila in nastavitve analiz v Beatraxu nimajo ustreznice.',
        'assumed_currency' => "Predpostavljeno: :currency — v tem izvozu ni bilo najdene nobene vrstice 'preferences.currencyCode'.",
        'assumed_budget_type' => "Predpostavljeno: :mode — v tem izvozu ni bilo najdene nobene vrstice 'preferences.budgetType'.",
        'changed_on_both_sides' => "Od zadnjega uvoza sta to spremenila tako izvorna datoteka kot Beatrax.\nLokalno: :local\nVir: :source\nNazadnje uvoženo: :baseline",
        'take_source' => 'Vrednost iz novega izvoza bo uporabljena, ko potrdiš — tvoja lokalna vrednost bo zamenjana.',
        'keep_local' => 'Tvoja lokalna vrednost bo obdržana — vrednost iz novega izvoza ne bo uporabljena.',
        'compared_values' => ":intro\nLokalno: :local · Vir: :source · Nazadnje uvoženo: :baseline",
    ],

    'value' => [
        'none' => '(brez vrednosti)',
        'quoted' => '„:value“',
    ],
];
