<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Ziele',
        'subtitle' => 'Verfolge deinen Fortschritt in Richtung deiner Sparziele.',
        'add_goal' => 'Ziel hinzufügen',
    ],

    'empty' => [
        'heading' => 'Noch keine Ziele',
        'body' => 'Lege einen Zielbetrag und ein Zieldatum fest, um deinen Sparfortschritt zu verfolgen.',
        'add_first' => 'Erstes Ziel hinzufügen',
    ],

    'status' => [
        'overdue' => 'Überfällig',
        'reached' => 'Erreicht',
        'completed' => 'Abgeschlossen',
        'archived' => 'Archiviert',
    ],

    'row' => [
        'edit' => 'Bearbeiten',
    ],

    'progress' => [
        'aria' => ':name: :pct % erreicht',
    ],

    'card' => [
        'target_date' => 'Zieldatum: :date',
    ],

    'projection' => [
        'target_reached' => 'Ziel erreicht',
        'closed_short' => 'Vor dem Ziel abgeschlossen',
        'add_contributions' => 'Füge Einzahlungen hinzu, um eine Prognose zu sehen',
        'not_enough_history' => 'Noch zu wenig Verlauf für ein Prognosedatum',
        'no_recent_contributions' => 'Keine aktuellen Einzahlungen, aus denen sich etwas prognostizieren lässt',
        'too_far_to_date' => 'Bei diesem Tempo zu weit entfernt für ein Datum',
        'est' => 'Vsl. :date ·',
        'projection_note' => '(Prognose)',
        'projected' => 'Voraussichtlich: :date',
    ],

    'archive' => [
        'confirm_question' => 'Dieses Ziel archivieren?',
        'close' => 'Schließen',
        'confirm_aria' => 'Archivieren von :name bestätigen',
        'archive' => 'Archivieren',
    ],

    'actions' => [
        'more_aria' => 'Weitere Aktionen für :name',
        'mark_complete' => 'Als abgeschlossen markieren',
        'mark_complete_caption' => 'Markieren',
        'archive' => 'Archivieren',
        'restore' => 'Wiederherstellen',
    ],

    'archived_disclosure' => 'Archiviertes Ziel (:count)|Archivierte Ziele (:count)',

    'form' => [
        'title_edit' => 'Ziel bearbeiten',
        'title_create' => 'Sparziel anlegen',
        'subtitle_edit' => 'Aktualisiere Name, Zielbetrag, Datum oder verknüpfte Rücklage.',
        'subtitle_create' => 'Lege einen Zielbetrag und ein Zieldatum fest, um deinen Sparfortschritt zu verfolgen.',
        'name' => 'Name',
        'name_placeholder' => 'z. B. Notgroschen',
        'target_amount' => 'Zielbetrag (:currency)',
        'target_date' => 'Zieldatum',
        'linked_pot' => 'Verknüpfte Rücklage (optional)',
        'no_pot' => 'Keine Rücklage — Umbuchungen erfassen',
        'linked_pot_help' => 'Bei einer Verknüpfung bestimmt der Saldo der Rücklage den Fortschritt dieses Ziels.',
        'save_changes' => 'Änderungen speichern',
        'save_goal' => 'Ziel speichern',
        'close' => 'Schließen',
    ],

    'summary' => [
        'see_all' => 'Alle anzeigen →',
        'no_goals' => 'Noch keine Ziele.',
        'add_first' => 'Erstes Ziel hinzufügen →',
    ],

    'notices' => [
        'goal_created' => 'Ziel erstellt.',
        'goal_updated' => 'Ziel aktualisiert.',
        'goal_marked_complete' => 'Ziel als abgeschlossen markiert.',
        'goal_archived' => 'Ziel archiviert.',
        'goal_restored' => 'Ziel wiederhergestellt.',
    ],

    'errors' => [
        'name' => 'Gib deinem Ziel einen Namen.',
        'date' => 'Wähle ein Zieldatum.',
        'date_invalid' => 'Wählen Sie ein gültiges Datum.',
        'date_before_start' => 'Wähle ein Datum am oder nach dem Start des Ziels.',
        'generic' => 'Das Ziel konnte nicht gespeichert werden. Prüfen Sie die Felder und versuchen Sie es erneut.',
        'amount' => 'Gib einen gültigen Betrag größer als null ein.',
        'pot_linked_category' => 'Diese Rücklage ist mit einer Kategorie verknüpft. Entferne diese Verknüpfung zuerst auf der Seite Rücklagen.',
        'pot_already_linked' => 'Diese Rücklage spart bereits auf ein anderes Ziel. Löse die Verknüpfung dort zuerst.',
        'pot_missing' => 'Diese Rücklage ist nicht mehr verfügbar. Wähle eine andere, oder lass dieses Ziel ohne Verknüpfung.',
    ],
];
