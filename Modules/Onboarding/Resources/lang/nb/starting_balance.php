<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 INNGÅENDE SALDO',
    'confirmed_aria' => 'bekreftet',
    'on_date' => 'per :date',

    'detected_h3' => 'Vi fant at :label startet på',
    'confirm' => 'Bekreft',
    'edit' => 'Rediger',

    'conflict_h3' => 'Vi så to verdier for denne kontoen — hvilken stemmer?',
    'conflict_legend' => 'Velg en inngående saldo',
    'conflict_from' => 'Fra :source:',
    'conflict_helper' => 'Vi velger den tidligste datoen som standard. Velg den riktige, eller rediger manuelt.',
    'edit_manually' => 'Rediger manuelt',

    'editing_h3' => 'Rediger inngående saldo for :label',
    'input_label' => 'INNGÅENDE SALDO',
    'minor_units' => '(minste enheter)',
    'on_date_label' => 'PER DATO',
    'cancel' => 'Avbryt',
    'save' => 'Lagre',

    'change' => 'Endre',

    'manual_h3' => 'Skriv inn inngående saldo for :label manuelt',
    'manual_lede' => 'Vi klarte ikke å finne en inngående saldo automatisk for denne kontoen. Skriv inn en manuelt, eller hopp over.',

    'unknown_state' => 'Ukjent korttilstand. Last inn veiviseren på nytt.',

    'errors' => [
        'account_not_set' => 'Konto er ikke angitt. Last inn veiviseren på nytt.',
        'invalid_amount' => 'Skriv inn et gyldig beløp.',
        'amount_range' => 'Skriv inn et beløp mellom :min og :max.',
        'pick_date' => 'Velg en dato.',
        'pick_valid_date' => 'Velg en gyldig dato.',
        'future_date' => 'Datoen for inngående saldo kan ikke ligge frem i tid.',
        'date_warning' => 'Dette er senere enn den første importerte transaksjonen din (:date). Oversikten din kan vise transaksjoner før denne datoen.',
    ],
];
