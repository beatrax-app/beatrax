<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Tikslas: :name',
        'category_goal' => 'Kategorijos :name tikslas',
        'schedule_untitled' => 'Suplanuota operacija be pavadinimo',
        'transaction' => 'Operacija: :name · :date · :amount',
        'transaction_unnamed' => 'Operacija',
        'amount_update' => 'Operacijos sumos atnaujinimas',
        'budget_history' => 'Biudžeto istorija :currency valiuta',
        'budget_file_currency' => 'Biudžeto failo valiuta',
        'budget_file_mode' => 'Biudžeto failo režimas',
    ],

    'conflict' => [
        'budget_assignment' => 'Biudžeto paskirstymas',
        'budget_for_month' => 'Biudžetas: :category · :month',
        'budget_for_category' => 'Biudžetas: :category',
        'category_name' => 'Kategorijos pavadinimas',
        'category_name_of' => 'Kategorijos „:name“ pavadinimas',
        'account_name' => 'Sąskaitos pavadinimas',
        'account_name_of' => 'Sąskaitos „:name“ pavadinimas',
        'transaction_amount' => 'Operacijos suma',
        'transaction_amount_of' => 'Suma: :name',
        'transaction_amount_of_dated' => 'Suma: :name · :date',
        'transaction_description' => 'Operacijos aprašas',
        'transaction_description_of' => 'Aprašas: :name',
        'transaction_description_of_dated' => 'Aprašas: :name · :date',
        'other' => 'Importuota reikšmė',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ši operacija susidūrė su kita jau įrašyta operacija (sutampa atspaudas) ir nebuvo importuota.',
        'reconciled_status_kept' => 'Šaltinio suderinimo būsenos pritaikyti nepavyko — ši operacija Beatrax sistemoje yra suderinta, ir tai pakeičia tik suderinimo panaikinimas. Palikta nepakeista.',

        // i18n-review: lt · reason.split_legs_without_category — the waiting
        // bucket reads „kategorijoje Be kategorijos“, repeating this locale's
        // own name for Uncategorized. A bare „laukia :uncategorized“ is
        // ungrammatical, so the repetition is what correctness costs here.
        'split_legs_without_category' => ':count padalijimo dalis iš :legs neturi kategorijos, o dalies be kategorijos išsaugoti negalima. Operacija importuota visa suma ir laukia kategorijoje :uncategorized.|:count padalijimo dalys iš :legs neturi kategorijos, o dalies be kategorijos išsaugoti negalima. Operacija importuota visa suma ir laukia kategorijoje :uncategorized.|:count padalijimo dalių iš :legs neturi kategorijos, o dalies be kategorijos išsaugoti negalima. Operacija importuota visa suma ir laukia kategorijoje :uncategorized.',
        'split_sum_mismatch' => 'Padalijimo dalys sudaro :legs, o operacija yra :total, tačiau padalijimas turi tiksliai atitikti savo operaciją. Operacija importuota visa suma, be savo dalių.',
        'split_unstorable' => 'Beatrax negali išsaugoti šio padalijimo tokio, koks jis yra, todėl operacija importuota atskirai, be savo dalių.',
        'goal_without_target_date' => 'Šis tikslas neturi tikslinės datos; Beatrax jos reikalauja, kad sukurtų taupymo tikslą.',
        'goal_without_name' => 'Šis tikslas neturi pavadinimo; Beatrax jo reikalauja, kad sukurtų taupymo tikslą.',
        'goal_def_unsupported' => 'categories.goal_def naudoja nepalaikomą (neplokščią) šablono formą — tikslas nebuvo importuotas.',
        'budget_currency_mismatch' => ':count biudžeto eilutė nebuvo importuota: tavo biudžetai vedami :envelope valiuta, o šiame eksporte biudžetas sudarytas :source valiuta.|:count biudžeto eilutės nebuvo importuotos: tavo biudžetai vedami :envelope valiuta, o šiame eksporte biudžetas sudarytas :source valiuta.|:count biudžeto eilučių nebuvo importuota: tavo biudžetai vedami :envelope valiuta, o šiame eksporte biudžetas sudarytas :source valiuta.',
        'amount_apply_collision' => 'Naujos šaltinio sumos pritaikyti nepavyko — ji susiduria su kitos operacijos atspaudu (ta pati sąskaita, data, valiuta ir kita šalis). Palikta nepakeista.',
        'amount_currency_mismatch' => 'Operacijų sumos nebuvo suderintos: šios operacijos vedamos :local valiuta, o šis eksportas jas nurodo :source valiuta. Palikta nepakeista.',
        'schedule_unsupported' => 'Suplanuotoms ir pasikartojančioms operacijoms Beatrax dar neturi kūrimo iš išorinio šaltinio kelio — jos išsaugotos tik kaip pastaba, o ne kaip veikianti pasikartojanti serija.',
        'saved_report_unsupported' => 'Beatrax neturi atitikmens išsaugotoms ataskaitoms ir analizės konfigūracijoms.',
        'assumed_currency' => "Priimta :currency — šiame eksporte nerasta eilutė 'preferences.currencyCode'.",
        'assumed_budget_type' => "Priimta :mode — šiame eksporte nerasta eilutė 'preferences.budgetType'.",
        'changed_on_both_sides' => "Nuo paskutinio importo tai pakeitė ir šaltinio failas, ir Beatrax.\nVietinė: :local\nŠaltinis: :source\nPaskutinį kartą importuota: :baseline",
        'take_source' => 'Naujo eksporto reikšmė bus pritaikyta, kai patvirtinsi — tavo vietinė reikšmė bus pakeista.',
        'keep_local' => 'Tavo vietinė reikšmė bus išsaugota — naujo eksporto reikšmė nebus pritaikyta.',
        'compared_values' => ":intro\nVietinė: :local · Šaltinis: :source · Paskutinį kartą importuota: :baseline",
    ],

    'value' => [
        'none' => '(nėra)',
        'quoted' => '„:value“',
    ],
];
