<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassenbuch',
    'heading' => 'Kassenbuch',
    'intro' => 'Erfasse Bargeld und andere Ausgaben außerhalb der Bank von Hand. Manuelle Einträge landen im selben Hauptbuch wie deine Importe — sie werden kategorisiert, einem Zahlungspartner zugeordnet, als wiederkehrend erkannt und für deinen Monat mitgezählt.',

    'direction' => 'Richtung',
    'expense' => 'Ausgabe',
    'income' => 'Einnahme',

    'amount' => 'Betrag (:symbol)',
    'date' => 'Datum',
    'counterparty' => 'Zahlungspartner',
    'counterparty_placeholder' => 'z. B. Bäckerei',
    'category' => 'Kategorie',
    'optional' => '(optional)',
    'uncategorized' => 'Nicht kategorisiert',
    'note' => 'Notiz',

    'add_entry' => 'Eintrag hinzufügen',
    'manual_entries' => 'Manuelle Einträge',
    'no_entries' => 'Noch keine manuellen Einträge.',
    'delete_entry' => 'Eintrag löschen',
    'delete_entry_caption' => 'Löschen',
    'delete' => 'Löschen',
    'delete_confirm' => 'Diesen Eintrag löschen?',
    'delete_keep' => 'Behalten',

    'errors' => [
        'amount_positive' => 'Gib einen Betrag größer als null ein.',
        'amount_too_large' => 'Dieser Betrag ist zu groß. Prüfe die Ziffern.',
        'amount_unreadable' => 'Der Betrag konnte nicht gelesen werden. Gib ihn mit höchstens :decimals Nachkommastelle ein, zum Beispiel :example.|Der Betrag konnte nicht gelesen werden. Gib ihn mit höchstens :decimals Nachkommastellen ein, zum Beispiel :example.',
        'amount_unreadable_whole' => 'Der Betrag konnte nicht gelesen werden. Diese Währung hat keine Nachkommastellen, gib also eine ganze Zahl ein, zum Beispiel :example.',
        'invalid_date' => 'Gib ein gültiges Datum ein.',
        'not_recorded' => 'Der Eintrag wurde nicht gespeichert. Versuche, ihn erneut hinzuzufügen.',
    ],

    'toast' => [
        'added' => 'Bareintrag hinzugefügt.',
        'removed' => 'Bareintrag entfernt.',
        'reconciled_locked' => 'Diese Transaktion ist abgeglichen. Hebe den Abgleich auf, um Änderungen vorzunehmen.',
    ],
];
