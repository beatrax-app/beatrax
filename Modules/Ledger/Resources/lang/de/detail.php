<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktion',
    'heading' => 'Transaktion',

    'counterparty' => 'Zahlungspartner',
    'description' => 'Beschreibung',
    'amount_native' => 'Betrag (Originalwährung)',
    'amount_settled' => 'Betrag (abgerechnet)',
    'effective_rate' => 'Effektiver Kurs',
    'ics_markup' => 'Enthält einen etwaigen ICS-Aufschlag.',

    'split' => [
        'category' => 'Kategorie',
        'open' => 'Auf Kategorien aufteilen',
        'heading' => 'Auf Kategorien aufteilen',
        'total' => 'Gesamt :amount',
        'tax_per_category' => 'Steuer-Markierungen werden unten pro Kategorie gesetzt.',
        'choose_category' => 'Wähle eine Kategorie',
        'note_label' => 'Notiz',
        'note_placeholder' => 'Notiz (optional)',
        'tax_deductible' => 'Steuerlich absetzbar',
        'remove_leg_aria' => 'Diese Kategorie entfernen',
        'add_category' => '+ Kategorie hinzufügen',
        'soft_cap' => ':count von ~20 Kategorien — kleine Beträge lieber zusammenfassen.',
        'remaining_zero' => 'Rest :amount ✓',
        'remaining_to_assign' => 'Noch zuzuordnen: :amount',
        'over_allocated' => ':amount zu viel zugeordnet — reduziere eine Position.',
        'save' => 'Aufteilung speichern',
        'saving' => 'Wird gespeichert…',
        'unsplit' => 'Aufteilung aufheben',
        'remove_to_one' => 'Wenn du das entfernst, bleibt eine Kategorie übrig — die Transaktion wird :category.',
        'remove_to_one_fallback' => 'diese Kategorie',
        'remove_category' => 'Kategorie entfernen',
        'keep_category' => 'Diese Kategorie behalten',
        'restore_single' => 'Als einzelne Kategorie wiederherstellen?',
        'survivor_legend' => 'Zu behaltende Kategorie',
        'confirm_unsplit' => 'Ja, Aufteilung aufheben',
        'keep_split' => 'Aufteilung behalten',
    ],

    'tax' => [
        'section_aria' => 'Steuer-Markierung',
        'label' => 'Steuerlich absetzbar',
    ],

    'reclassify' => [
        'heading' => 'Neu einordnen',
        'help' => 'Überschreibe den erkannten Typ. Wenn diese Transaktion mit einer anderen gepaart ist, hebt ein Typ außer Umbuchung die Paarung auf beiden Seiten auf.',
        'choose_aria' => 'Neuen Transaktionstyp wählen',
        'choose_option' => 'Wähle einen Typ…',
        'save' => 'Speichern',
    ],

    'type_label' => [
        'expense' => 'Ausgabe',
        'income' => 'Einnahme',
        'transfer_out' => 'Ausgehende Überweisung',
        'transfer_in' => 'Eingehende Überweisung',
        'fee' => 'Gebühr',
        'refund' => 'Erstattung',
        'adjustment' => 'Korrektur',
    ],

    'note' => [
        'heading' => 'Notiz',
        'help' => 'Persönliche Notiz zu dieser Transaktion. Nur für dich sichtbar.',
        'label' => 'Notiz',
        'placeholder' => 'Notiz hinzufügen…',
        'save' => 'Notiz speichern',
        'saved' => 'Gespeichert',
    ],

    'reassign' => [
        'heading' => 'Zahlungspartner neu zuordnen',
        'help' => 'Überschreibe den ermittelten Zahlungspartner für diese Transaktion.',
        'choose_aria' => 'Zahlungspartner wählen',
        'choose_option' => 'Wähle einen Zahlungspartner…',
        'submit' => 'Neu zuordnen',
    ],

    'goal' => [
        'heading' => 'Sparziel',
        'help' => 'Diese Transaktion auf eines deiner Sparziele anrechnen.',
        'choose_aria' => 'Sparziel wählen',
        'choose_option' => 'Ziel wählen…',
        'submit' => 'Zum Ziel hinzufügen',
        'remove_aria' => ':name entfernen',
    ],

    'delete' => [
        'heading' => 'Transaktion löschen',
        'help' => 'Entfernt diese Transaktion dauerhaft. Diese Aktion lässt sich nicht rückgängig machen.',
        'button' => 'Löschen',
        'confirm_prompt' => 'Bist du sicher?',
        'confirm' => 'Ja, löschen',
        'cancel' => 'Abbrechen',
    ],

    'chain' => [
        'view' => 'Kette ansehen',
    ],

    'toast' => [
        'reconciled_locked' => 'Diese Transaktion ist abgeglichen. Hebe den Abgleich auf, um Änderungen vorzunehmen.',
        'reclassified_pair_removed' => 'Neu eingeordnet als :type — Paarung entfernt',
        'reclassified' => 'Neu eingeordnet als :type',
        'note_saved' => 'Notiz gespeichert',
        'unreconciled' => 'Abgleich aufgehoben — du kannst diese Transaktion wieder bearbeiten.',
        'counterparty_updated' => 'Zahlungspartner aktualisiert',
        'goal_attributed' => 'Wird auf dieses Ziel angerechnet',
        'goal_attribution_removed' => 'Wird nicht mehr auf dieses Ziel angerechnet',
        'split_saved' => 'Aufteilung gespeichert',
        'removed_one_remains' => 'Entfernt — eine Kategorie bleibt übrig',
        'unsplit_restored' => 'Aufteilung aufgehoben — auf eine einzelne Kategorie zurückgesetzt',
    ],

    'errors' => [
        'totals_must_match' => 'Speichern fehlgeschlagen — die Summe der Positionen muss exakt der Transaktionssumme entsprechen.',
        'not_found' => 'Transaktion nicht gefunden.',
        'amount_zero' => 'Der Betrag darf nicht :amount sein',
        'choose_category' => 'Wähle eine Kategorie.',
        'choose_before_removing' => 'Wähle eine Kategorie, bevor du entfernst.',
        'choose_before_unsplitting' => 'Wähle eine Kategorie, bevor du die Aufteilung aufhebst.',
        'not_found_or_unowned' => 'Transaktion nicht gefunden oder gehört nicht diesem Benutzer.',
        'reconciled_split' => 'Diese Transaktion ist abgeglichen. Hebe den Abgleich auf, um die Aufteilung zu ändern.',
        'not_splittable' => "Der Transaktionstyp ':type' lässt sich nicht aufteilen.",
        'min_two_legs' => 'Eine Aufteilung braucht mindestens 2 Positionen.',
        'legs_non_zero' => 'Positionsbeträge dürfen nicht null sein.',
        'legs_parent_sign' => 'Positionsbeträge müssen dasselbe Vorzeichen wie die Haupttransaktion haben.',
        'leg_category_not_accessible' => 'Positionskategorie nicht gefunden oder für diesen Benutzer nicht zugänglich.',
        'survivor_not_accessible' => 'Verbleibende Kategorie nicht gefunden oder für diesen Benutzer nicht zugänglich.',
        'survivor_must_be_current' => 'Die verbleibende Kategorie muss eine der aktuellen Positionskategorien der Aufteilung sein.',
    ],
];
