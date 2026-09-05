<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Tavoite: :name',
        'category_goal' => 'Kategorian :name tavoite',
        'schedule_untitled' => 'Nimetön ajastettu tapahtuma',
        'transaction' => 'Tapahtuma: :name · :date · :amount',
        'transaction_unnamed' => 'Tapahtuma',
        'amount_update' => 'Tapahtuman summan päivitys',
        'budget_history' => 'Budjettihistoria valuutassa :currency',
        'budget_file_currency' => 'Budjettitiedoston valuutta',
        'budget_file_mode' => 'Budjettitiedoston tila',
    ],

    'conflict' => [
        'budget_assignment' => 'Budjetin jako',
        'budget_for_month' => 'Budjetti: :category · :month',
        'budget_for_category' => 'Budjetti: :category',
        'category_name' => 'Kategorian nimi',
        'category_name_of' => 'Kategorian ”:name” nimi',
        'account_name' => 'Tilin nimi',
        'account_name_of' => 'Tilin ”:name” nimi',
        'transaction_amount' => 'Tapahtuman summa',
        'transaction_amount_of' => 'Summa: :name',
        'transaction_amount_of_dated' => 'Summa: :name · :date',
        'transaction_description' => 'Tapahtuman kuvaus',
        'transaction_description_of' => 'Kuvaus: :name',
        'transaction_description_of_dated' => 'Kuvaus: :name · :date',
        'other' => 'Tuotu arvo',
    ],

    'reason' => [
        'fingerprint_collision' => 'Tämä tapahtuma törmäsi toiseen jo kirjattuun tapahtumaan (sama sormenjälki), eikä sitä tuotu.',

        // i18n-review: fi · reason.split_legs_without_category — the count is
        // moved behind the elative phrase because Finnish counts that way, and
        // both arms are then identical: *osasta* does not move after a numeral
        // and *on* stays singular whatever the count.
        'split_legs_without_category' => 'Jaon :legs osasta :count on ilman kategoriaa, eikä osaa voi tallentaa ilman sitä. Tapahtuma tuotiin täydellä summallaan ja odottaa kategoriassa :uncategorized.|Jaon :legs osasta :count on ilman kategoriaa, eikä osaa voi tallentaa ilman sitä. Tapahtuma tuotiin täydellä summallaan ja odottaa kategoriassa :uncategorized.',
        'split_sum_mismatch' => 'Jaon osat ovat yhteensä :legs, mutta tapahtuma on :total, ja jaon on vastattava tapahtumaansa täsmälleen. Tapahtuma tuotiin täydellä summallaan, ilman osiaan.',
        'split_unstorable' => 'Beatrax ei voi tallentaa tätä jakoa sellaisenaan, joten tapahtuma tuotiin yksin, ilman osiaan.',
        'goal_without_target_date' => 'Tällä tavoitteella ei ole tavoitepäivää; Beatrax vaatii sen säästötavoitteen luomiseen.',
        'goal_without_name' => 'Tällä tavoitteella ei ole nimeä; Beatrax vaatii sen säästötavoitteen luomiseen.',
        'goal_def_unsupported' => 'categories.goal_def käyttää tukematonta (ei-litteää) mallin muotoa — tavoitetta ei tuotu.',
        'budget_currency_mismatch' => ':count budjettirivi jäi tuomatta: budjettejasi pidetään valuutassa :envelope, ja tämä vienti budjetoi valuutassa :source.|:count budjettiriviä jäi tuomatta: budjettejasi pidetään valuutassa :envelope, ja tämä vienti budjetoi valuutassa :source.',
        'amount_apply_collision' => 'Lähteen uutta summaa ei voitu ottaa käyttöön — se törmää toisen tapahtuman sormenjälkeen (sama tili, päivä, valuutta ja vastapuoli). Jätettiin ennalleen.',
        'amount_currency_mismatch' => 'Tapahtumien summia ei täsmäytetty: näitä tapahtumia pidetään valuutassa :local, ja tämä vienti ilmoittaa ne valuutassa :source. Jätettiin ennalleen.',
        'schedule_unsupported' => 'Beatraxilla ei ole vielä tapaa luoda ajastettuja ja toistuvia tapahtumia ulkoisesta lähteestä — ne on säilytetty vain muistiinpanona, ei elävänä toistuvana sarjana.',
        'saved_report_unsupported' => 'Beatraxilla ei ole vastinetta tallennetuille raporteille eikä analyysimäärityksille.',
        'assumed_currency' => "Oletettiin :currency — tästä viennistä ei löytynyt riviä 'preferences.currencyCode'.",
        'assumed_budget_type' => "Oletettiin :mode — tästä viennistä ei löytynyt riviä 'preferences.budgetType'.",
        'changed_on_both_sides' => "Sekä lähdetiedosto että Beatrax ovat muuttaneet tätä viime tuonnin jälkeen.\nPaikallinen: :local\nLähde: :source\nViimeksi tuotu: :baseline",
        'take_source' => 'Uuden viennin arvo otetaan käyttöön, kun vahvistat — paikallinen arvosi korvataan.',
        'keep_local' => 'Paikallinen arvosi säilytetään — uuden viennin arvoa ei oteta käyttöön.',
        'compared_values' => ":intro\nPaikallinen: :local · Lähde: :source · Viimeksi tuotu: :baseline",
    ],

    'value' => [
        'none' => '(ei mitään)',
        'quoted' => '”:value”',
    ],
];
