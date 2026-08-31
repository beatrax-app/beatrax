<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Doel: :name',
        'category_goal' => 'Doel voor :name',
        'schedule_untitled' => 'Naamloze geplande transactie',
        'transaction' => 'Transactie: :name · :date · :amount',
        'transaction_unnamed' => 'Transactie',
        'amount_update' => 'Bijwerking van transactiebedrag',
        'budget_history' => 'Budgetgeschiedenis in :currency',
        'budget_file_currency' => 'Valuta van het budgetbestand',
        'budget_file_mode' => 'Modus van het budgetbestand',
    ],

    'conflict' => [
        'budget_assignment' => 'Budgettoewijzing',
        'budget_for_month' => 'Budget voor :category · :month',
        'budget_for_category' => 'Budget voor :category',
        'category_name' => 'Categorienaam',
        'category_name_of' => 'Categorienaam van “:name”',
        'account_name' => 'Rekeningnaam',
        'account_name_of' => 'Rekeningnaam van “:name”',
        'transaction_amount' => 'Transactiebedrag',
        'transaction_amount_of' => 'Bedrag van :name',
        'transaction_amount_of_dated' => 'Bedrag van :name · :date',
        'transaction_description' => 'Transactieomschrijving',
        'transaction_description_of' => 'Omschrijving van :name',
        'transaction_description_of_dated' => 'Omschrijving van :name · :date',
        'other' => 'Geïmporteerde waarde',
    ],

    'reason' => [
        'fingerprint_collision' => 'Deze transactie botste met een al vastgelegde transactie (identieke vingerafdruk) en is niet geïmporteerd.',
        'split_legs_without_category' => ':count splitsingsregel van :legs heeft geen categorie, en een splitsingsregel kan niet zonder categorie worden opgeslagen. De transactie is voor het volledige bedrag geïmporteerd en staat in de categorie :uncategorized.|:count splitsingsregels van :legs hebben geen categorie, en een splitsingsregel kan niet zonder categorie worden opgeslagen. De transactie is voor het volledige bedrag geïmporteerd en staat in de categorie :uncategorized.',
        'split_sum_mismatch' => 'De regels van de splitsing tellen op tot :legs, maar de transactie is :total, en een splitsing moet exact met haar transactie overeenkomen. De transactie is voor het volledige bedrag geïmporteerd, zonder de bijbehorende regels.',
        'split_unstorable' => 'Beatrax kan deze splitsing niet opslaan zoals ze nu is, dus is de transactie op zichzelf geïmporteerd, zonder de bijbehorende regels.',
        'goal_without_target_date' => 'Dit doel heeft geen streefdatum; Beatrax heeft er een nodig om een spaardoel aan te maken.',
        'goal_without_name' => 'Dit doel heeft geen naam; Beatrax heeft er een nodig om een spaardoel aan te maken.',
        'goal_def_unsupported' => 'categories.goal_def gebruikt een niet-ondersteunde (niet-platte) sjabloonvorm — het doel is niet geïmporteerd.',
        'budget_currency_mismatch' => ':count budgetregel is niet geïmporteerd: jouw budgetten worden bijgehouden in :envelope en deze export voert zijn budget in :source.|:count budgetregels zijn niet geïmporteerd: jouw budgetten worden bijgehouden in :envelope en deze export voert zijn budget in :source.',
        'amount_apply_collision' => 'Het nieuwe bedrag uit de bron kon niet worden toegepast — het botst met de vingerafdruk van een andere transactie (zelfde rekening, datum, valuta en tegenpartij). Ongewijzigd gelaten.',
        'schedule_unsupported' => 'Geplande en terugkerende transacties kunnen in Beatrax nog niet vanuit een externe bron worden aangemaakt — alleen als notitie bewaard, niet als actieve reeks in Terugkerend.',
        'saved_report_unsupported' => 'Opgeslagen rapporten en analyse-instellingen hebben geen equivalent in Beatrax.',
        'assumed_currency' => "Aangenomen: :currency — er is in deze export geen rij 'preferences.currencyCode' gevonden.",
        'assumed_budget_type' => "Aangenomen: :mode — er is in deze export geen rij 'preferences.budgetType' gevonden.",
        'changed_on_both_sides' => "Zowel het bronbestand als Beatrax heeft dit sinds de laatste import gewijzigd.\nLokaal: :local\nBron: :source\nLaatst geïmporteerd: :baseline",
        'take_source' => 'De waarde uit de nieuwe export wordt toegepast zodra je bevestigt — jouw lokale waarde wordt vervangen.',
        'keep_local' => 'Jouw lokale waarde blijft behouden — de waarde uit de nieuwe export wordt niet toegepast.',
        'compared_values' => ":intro\nLokaal: :local · Bron: :source · Laatst geïmporteerd: :baseline",
    ],

    'value' => [
        'none' => '(geen)',
        'quoted' => '“:value”',
    ],
];
