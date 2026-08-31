<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgets',
        'subtitle' => 'Weise alles zu — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Vorheriger Zeitraum',
        'next_aria' => 'Nächster Zeitraum',
    ],

    'ready' => [
        'label' => 'Bereit zum Zuweisen',
        'overassigned' => 'Du hast mehr zugewiesen, als du hast — verringere einen Umschlag oder warte auf weitere Einnahmen.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Noch nichts zugewiesen',
        'copy_hint' => 'Kopiere den Plan des letzten Monats oder klicke unten in eine Zelle, um mit dem Zuweisen zu beginnen.',
        'copy_hint_touch' => 'Kopiere den Plan des letzten Monats oder tippe unten in eine Zelle, um mit dem Zuweisen zu beginnen.',
        'first_hint' => 'Klicke unten in eine Zelle, um deinen ersten Monat zuzuweisen.',
        'first_hint_touch' => 'Tippe unten in eine Zelle, um deinen ersten Monat zuzuweisen.',
        'copy_button' => 'Letzten Monat kopieren',
    ],

    'no_categories' => [
        'heading' => 'Noch keine Ausgabenkategorien',
        'body' => 'Füge eine Ausgabenkategorie hinzu, um ihr Geld zuzuweisen.',
    ],

    'table' => [
        'category' => 'Kategorie',
        'assigned' => 'Zugewiesen',
        'carried_in' => 'Übertragen',
        'moved' => 'Umgebucht',
        'spent' => 'Ausgegeben',
        'available' => 'Verfügbar',
        'if_overspent' => 'Bei Überschreitung',
        'notify_at' => 'Benachrichtigen bei',
        'actions' => 'Aktionen',
    ],

    'badge' => [
        'carries_negative' => 'Überträgt Minus',
        'unconverted_aria' => 'Ausgaben in einer Währung ohne verfügbaren Kurs zählen hier nicht mit — siehe Übersicht',
        'unconverted_title' => 'Ausgaben ohne verfügbaren Kurs zählen hier nicht mit — siehe Übersicht',
        'over_budget' => ':count über Budget',
    ],

    'row' => [
        'assigned_aria' => 'Zugewiesen für :category',
        'overspend_aria' => 'Wenn :category überschritten wird',
        'notify_aria' => 'Benachrichtige mich bei genutztem Prozentsatz für :category',
        'move_money' => 'Geld verschieben',
        'move' => 'Verschieben',
    ],

    'overspend' => [
        'reduce' => 'Den zuzuweisenden Betrag des nächsten Monats verringern',
        'carry' => 'Das Minus in diesem Umschlag übertragen',
    ],

    'history' => [
        'show' => 'Verlauf anzeigen ↓',
        'hide' => 'Verlauf ausblenden ↑',
        'moved_from' => 'Verschoben aus :category',
        'moved_to' => 'Verschoben nach :category',
        'moved_unreadable' => 'Mit :category verschoben, von einer neueren Version von Beatrax',
        'undo' => 'Rückgängig',
    ],

    'phone' => [
        'spent' => 'Ausgegeben :amount',
        'carried_in' => 'Übertragen :amount',
        'moved' => 'Umgebucht :amount',
        'available' => 'Verfügbar :amount',
        'notify_at' => 'Benachrichtigen bei',
    ],

    'modal' => [
        'move_from' => 'Verschieben aus :name',
        'move_from_fallback' => 'Umschlag',
        'move_to' => 'Verschieben nach',
        'no_other' => 'Keine anderen Umschläge',
        'select' => 'Wähle einen Umschlag',
        'amount' => 'Betrag',
        'available_in' => 'Verfügbar in :name: :amount',
        'note' => 'Notiz (optional)',
        'note_placeholder' => 'z. B. Überschreitung beim Essengehen ausgleichen',
        'cancel' => 'Abbrechen',
        'move_funds' => 'Geld verschieben',
    ],

    'glance' => [
        'see_all' => 'Alle anzeigen →',
    ],

    'notices' => [
        'invalid_amount' => 'Gib einen gültigen Betrag ein.',
        'threshold_range' => 'Gib eine ganze Zahl zwischen 1 und 200 ein.',
        'copied_last_month' => 'Plan des letzten Monats kopiert.',
        'choose_envelope' => 'Wähle einen Umschlag, in den du das Geld verschieben willst.',
        'amount_positive' => 'Gib einen Betrag größer als null ein.',
        'move_failed' => 'Die Verschiebung konnte nicht abgeschlossen werden — versuch es noch mal.',
        'money_moved' => 'Geld verschoben.',
        'move_undone' => 'Verschiebung rückgängig gemacht.',
    ],

    'errors' => [
        'assigned_negative' => 'Der zugewiesene Betrag darf nicht negativ sein.',
        'invalid_overspend_mode' => 'Ungültiger Überschreitungsmodus.',
        'threshold_range' => 'Die Benachrichtigungsschwelle muss zwischen 1 und 200 liegen.',
        'same_envelope' => 'Quell- und Zielumschlag müssen unterschiedlich sein.',
        'non_positive_amount' => 'Ungültiger oder nicht positiver Betrag.',
        'category_not_found' => 'Kategorie nicht gefunden oder für den Benutzer nicht zugänglich.',
    ],
];
