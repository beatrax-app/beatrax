<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 ANFANGSSALDO',
    'confirmed_aria' => 'bestätigt',
    'on_date' => 'am :date',

    'detected_h3' => 'Erkannter Anfangssaldo für :label',
    'confirm' => 'Bestätigen',
    'edit' => 'Bearbeiten',

    'conflict_h3' => 'Wir haben zwei Werte für dieses Konto gesehen — welcher stimmt?',
    'conflict_legend' => 'Wähle einen Anfangssaldo',
    'conflict_from' => 'Aus :source:',
    'conflict_helper' => 'Standardmäßig nehmen wir das früheste Datum. Wähle den richtigen Wert oder bearbeite ihn manuell.',
    'edit_manually' => 'Manuell bearbeiten',

    'editing_h3' => 'Anfangssaldo für :label bearbeiten',
    'input_label' => 'ANFANGSSALDO',
    'minor_units' => '(kleinste Einheiten)',
    'on_date_label' => 'DATUM',
    'cancel' => 'Abbrechen',
    'save' => 'Speichern',

    'change' => 'Ändern',

    'manual_h3' => 'Anfangssaldo für :label manuell eingeben',
    'manual_lede' => 'Wir konnten für dieses Konto keinen Anfangssaldo automatisch erkennen. Gib einen ein oder überspringe das.',

    'unknown_state' => 'Unbekannter Kartenstatus. Lade den Assistenten neu.',

    'errors' => [
        'account_not_set' => 'Konto nicht gesetzt. Lade den Assistenten neu.',
        'invalid_amount' => 'Gib einen gültigen Betrag ein.',
        'amount_range' => 'Gib einen Betrag zwischen :min und :max ein.',
        'pick_date' => 'Wähle ein Datum.',
        'pick_valid_date' => 'Wähle ein gültiges Datum.',
        'future_date' => 'Das Datum des Anfangssaldos darf nicht in der Zukunft liegen.',
        'date_warning' => 'Das liegt nach deiner ersten importierten Transaktion (:date). Dein Dashboard zeigt möglicherweise Transaktionen vor diesem Datum.',
    ],
];
