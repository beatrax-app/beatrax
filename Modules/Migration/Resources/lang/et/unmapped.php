<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Eesmärk: :name',
        'category_goal' => 'Kategooria :name eesmärk',
        'schedule_untitled' => 'Nimeta ajastatud tehing',
        'transaction' => 'Tehing: :name · :date · :amount',
        'transaction_unnamed' => 'Tehing',
        'amount_update' => 'Tehingusumma uuendus',
        'budget_history' => 'Eelarve ajalugu valuutas :currency',
        'budget_file_currency' => 'Eelarvefaili valuuta',
        'budget_file_mode' => 'Eelarvefaili režiim',
    ],

    'conflict' => [
        'budget_assignment' => 'Eelarve jaotus',
        'budget_for_month' => 'Eelarve: :category · :month',
        'budget_for_category' => 'Eelarve: :category',
        'category_name' => 'Kategooria nimi',
        'category_name_of' => 'Kategooria „:name“ nimi',
        'account_name' => 'Konto nimi',
        'account_name_of' => 'Konto „:name“ nimi',
        'transaction_amount' => 'Tehingu summa',
        'transaction_amount_of' => 'Summa: :name',
        'transaction_amount_of_dated' => 'Summa: :name · :date',
        'transaction_description' => 'Tehingu kirjeldus',
        'transaction_description_of' => 'Kirjeldus: :name',
        'transaction_description_of_dated' => 'Kirjeldus: :name · :date',
        'other' => 'Imporditud väärtus',
    ],

    'reason' => [
        'fingerprint_collision' => 'See tehing põrkus juba salvestatud tehinguga (identne sõrmejälg) ja jäi importimata.',
        'reconciled_status_kept' => 'Allika kontrollistaatust ei saanud rakendada — see tehing on Beatraxis kooskõlastatud ja seda muudab ainult kooskõlastuse tühistamine. Jäeti muutmata.',

        // i18n-review: et · reason.split_legs_without_category — the count is
        // moved behind the elative phrase because Estonian counts that way, and
        // both arms are then identical: after a numeral *osa* is the partitive
        // singular, which is the same word, and *on* does not move.
        'split_legs_without_category' => 'Jaotuse :legs osast :count on ilma kategooriata ja ilma selleta ei saa osa salvestada. Tehing imporditi täissummas ja ootab kategoorias :uncategorized.|Jaotuse :legs osast :count on ilma kategooriata ja ilma selleta ei saa osa salvestada. Tehing imporditi täissummas ja ootab kategoorias :uncategorized.',
        'split_sum_mismatch' => 'Jaotuse osad annavad kokku :legs, kuid tehing on :total, jaotus peab aga oma tehinguga täpselt kokku langema. Tehing imporditi täissummas, ilma osadeta.',
        'split_unstorable' => 'Beatrax ei saa seda jaotust sellisena salvestada, seega imporditi tehing üksinda, ilma osadeta.',
        'goal_without_target_date' => 'Sellel eesmärgil pole sihtkuupäeva; Beatrax nõuab seda säästueesmärgi loomiseks.',
        'goal_without_name' => 'Sellel eesmärgil pole nime; Beatrax nõuab seda säästueesmärgi loomiseks.',
        'goal_def_unsupported' => 'categories.goal_def kasutab toetamata (mitte-lameda) malli kuju — eesmärki ei imporditud.',
        'budget_currency_mismatch' => ':count eelarverida jäi importimata: sinu eelarveid peetakse valuutas :envelope, see eksport aga eelarvestab valuutas :source.|:count eelarverida jäi importimata: sinu eelarveid peetakse valuutas :envelope, see eksport aga eelarvestab valuutas :source.',
        'amount_apply_collision' => 'Allika uut summat ei saanud rakendada — see põrkub teise tehingu sõrmejäljega (sama konto, kuupäev, valuuta ja vastaspool). Jäetud muutmata.',
        'amount_currency_mismatch' => 'Tehingusummasid ei võrreldud: neid tehinguid peetakse valuutas :local, see eksport aga esitab need valuutas :source. Jäid muutmata.',
        'schedule_unsupported' => 'Beatraxil pole ajastatud ja korduvate tehingute jaoks veel välisest allikast loomise teed — need on säilitatud ainult märkusena, mitte elava korduvate tehingute seeriana.',
        'saved_report_unsupported' => 'Beatraxil pole salvestatud aruannete ja analüüsiseadistuste vastet.',
        'assumed_currency' => "Eeldati :currency — sellest ekspordist ei leitud rida 'preferences.currencyCode'.",
        'assumed_budget_type' => "Eeldati :mode — sellest ekspordist ei leitud rida 'preferences.budgetType'.",
        'changed_on_both_sides' => "Nii lähtefail kui ka Beatrax on seda pärast viimast importi muutnud.\nKohalik: :local\nAllikas: :source\nViimati imporditud: :baseline",
        'take_source' => 'Uue ekspordi väärtus rakendatakse, kui kinnitad — sinu kohalik väärtus asendatakse.',
        'keep_local' => 'Sinu kohalik väärtus säilib — uue ekspordi väärtust ei rakendata.',
        'compared_values' => ":intro\nKohalik: :local · Allikas: :source · Viimati imporditud: :baseline",
    ],

    'value' => [
        'none' => '(puudub)',
        'quoted' => '„:value“',
    ],
];
