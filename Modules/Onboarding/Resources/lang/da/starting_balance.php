<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 STARTSALDO',
    'confirmed_aria' => 'bekræftet',
    'on_date' => 'pr. :date',

    'detected_h3' => 'Vi fandt, at :label startede på',
    'confirm' => 'Bekræft',
    'edit' => 'Redigér',

    'conflict_h3' => 'Vi så to værdier for denne konto — hvilken er rigtig?',
    'conflict_legend' => 'Vælg en startsaldo',
    'conflict_from' => 'Fra :source:',
    'conflict_helper' => 'Vi vælger som standard den tidligste dato. Vælg den rigtige, eller redigér manuelt.',
    'edit_manually' => 'Redigér manuelt',

    'editing_h3' => 'Redigér startsaldoen for :label',
    'input_label' => 'STARTSALDO',
    'minor_units' => '(mindste enheder)',
    'on_date_label' => 'PR. DATO',
    'cancel' => 'Annullér',
    'save' => 'Gem',

    'change' => 'Ændr',

    'manual_h3' => 'Indtast startsaldoen for :label manuelt',
    'manual_lede' => 'Vi kunne ikke finde en startsaldo automatisk for denne konto. Indtast en manuelt, eller spring over.',

    'unknown_state' => 'Ukendt korttilstand. Genindlæs guiden.',

    'errors' => [
        'account_not_set' => 'Konto er ikke angivet. Genindlæs guiden.',
        'invalid_amount' => 'Indtast et gyldigt beløb.',
        'amount_range' => 'Indtast et beløb mellem :min og :max.',
        'pick_date' => 'Vælg en dato.',
        'pick_valid_date' => 'Vælg en gyldig dato.',
        'future_date' => 'Datoen for startsaldoen kan ikke ligge i fremtiden.',
        'date_warning' => 'Det er senere end din første importerede transaktion (:date). Dit overblik kan vise transaktioner før denne dato.',
    ],
];
