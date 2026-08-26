<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 INGÅENDE SALDO',
    'confirmed_aria' => 'bekräftat',
    'on_date' => 'per :date',

    'detected_h3' => 'Vi hittade att :label började på',
    'confirm' => 'Bekräfta',
    'edit' => 'Redigera',

    'conflict_h3' => 'Vi såg två värden för det här kontot — vilket stämmer?',
    'conflict_legend' => 'Välj ett ingående saldo',
    'conflict_from' => 'Från :source:',
    'conflict_helper' => 'Vi väljer det tidigaste datumet som standard. Välj rätt värde eller redigera manuellt.',
    'edit_manually' => 'Redigera manuellt',

    'editing_h3' => 'Redigera ingående saldo för :label',
    'input_label' => 'INGÅENDE SALDO',
    'minor_units' => '(minsta enheter)',
    'on_date_label' => 'PER DATUM',
    'cancel' => 'Avbryt',
    'save' => 'Spara',

    'change' => 'Ändra',

    'manual_h3' => 'Ange ingående saldo för :label manuellt',
    'manual_lede' => 'Vi kunde inte hitta något ingående saldo automatiskt för det här kontot. Ange ett manuellt eller hoppa över.',

    'unknown_state' => 'Okänt kortläge. Ladda om guiden.',

    'errors' => [
        'account_not_set' => 'Konto inte angivet. Ladda om guiden.',
        'invalid_amount' => 'Ange ett giltigt belopp.',
        'amount_range' => 'Ange ett belopp mellan :min och :max.',
        'pick_date' => 'Välj ett datum.',
        'pick_valid_date' => 'Välj ett giltigt datum.',
        'future_date' => 'Datumet för ingående saldo kan inte ligga i framtiden.',
        'date_warning' => 'Det här är senare än din första importerade transaktion (:date). Din översikt kan visa transaktioner före det datumet.',
    ],
];
