<?php

declare(strict_types=1);

return [
    'page_title' => 'Rücklagen · Beatrax',
    'heading' => 'Rücklagen',
    'subtitle' => 'Virtuelle Teilsalden, herausgelöst aus dem tatsächlichen Kontosaldo.',
    'add_pot' => 'Rücklage hinzufügen',

    'pot_fallback' => 'Rücklage',

    'empty' => [
        'heading' => 'Noch keine Rücklagen',
        'body' => 'Lege innerhalb eines Kontos virtuelle Teilsalden an, um dein Geld zu ordnen — ganz ohne echte Überweisung.',
        'cta' => 'Erste Rücklage anlegen',
        'no_accounts_cta' => 'Kontoauszug importieren',
    ],

    'common' => [
        'cancel' => 'Abbrechen',
        'amount' => 'Betrag',
        'note_optional' => 'Notiz (optional)',
    ],

    'actions' => [
        'fund' => 'Einzahlen',
        'move' => 'Verschieben',
        'edit' => 'Bearbeiten',
        'withdraw' => 'Entnehmen',
        'archive' => 'Archivieren',
        'restore' => 'Wiederherstellen',
    ],

    'recon' => [
        'over_allocated' => 'Rücklagen übersteigen den tatsächlichen Saldo um :amount — verteile neu, um das zu beheben',
        'real_balance' => 'Tatsächlicher Saldo:',
        'allocated' => 'Zugeteilt:',
        'unallocated' => 'Nicht zugeteilt:',
    ],

    'chip' => [
        'goal' => 'Ziel:',
        'goal_name_fallback' => 'Ziel',
        'category_fallback' => 'Kategorie',
    ],

    'coverage' => [
        'spent' => 'ausgegeben',
        'in_pot' => 'in der Rücklage',
    ],

    'archive_confirm' => 'Diese Rücklage archivieren? Der Saldo von :amount geht zurück auf nicht zugeteilt.',
    'confirm_archive_aria' => 'Archivieren von :name bestätigen',
    'more_actions_aria' => 'Weitere Aktionen für :name',

    'history' => [
        'show' => 'Verlauf anzeigen ↓',
        'hide' => 'Verlauf ausblenden ↑',
        'truncated' => 'Letzte Bewegungen: :shown von :count',
    ],

    'movement' => [
        'fund' => 'Einzahlung',
        'withdraw' => 'Entnahme',
        'moved_from' => 'Verschoben aus :name',
        'moved_to' => 'Verschoben nach :name',
        'unreadable' => 'Von einer neueren Version von Beatrax erfasst',
        'released_on_archive' => 'Bei Archivierung freigegeben',
    ],

    'archived' => [
        'toggle' => 'Archivierte Rücklage (:count)|Archivierte Rücklagen (:count)',
        'badge' => 'Archiviert',
    ],

    'form' => [
        'create_title' => 'Rücklage anlegen',
        'edit_title' => 'Rücklage bearbeiten',
        'create_subtitle' => 'Gib einem virtuellen Teilsaldo innerhalb eines Kontos einen Namen.',
        'edit_subtitle' => 'Aktualisiere den Namen oder die Verknüpfung dieser Rücklage.',
        'name' => 'Name',
        'name_placeholder' => 'z. B. Urlaubskasse',
        'account' => 'Konto',
        'select_account' => 'Wähle ein Konto',
        'initial_amount' => 'Startbetrag (optional)',
        'initial_amount_help' => 'Der Betrag wird vom nicht zugeteilten Saldo abgezogen. Leer lassen, um sie leer anzulegen.',
        'link_to' => 'Verknüpfen mit (optional)',
        'link_goal' => 'Ziel',
        'link_none' => 'Keine',
        'select_goal' => 'Wähle ein Ziel',
        'save_pot' => 'Rücklage speichern',
        'save_changes' => 'Änderungen speichern',
    ],

    'fund' => [
        'title' => 'In Rücklage einzahlen',
        'heading' => 'In :name einzahlen',
        'submit' => 'In Rücklage einzahlen',
        'note_placeholder' => 'z. B. Monatliche Sparrate',
        'available' => 'Verfügbar zum Zuteilen: :amount (nicht zugeteilt)',
    ],

    'move' => [
        'title' => 'Geld verschieben',
        'heading' => 'Verschieben aus :name',
        'to' => 'Verschieben nach',
        'select_pot' => 'Wähle eine Rücklage',
        'no_others_short' => 'Keine anderen Rücklagen',
        'no_others' => 'Keine anderen Rücklagen auf diesem Konto',
        'submit' => 'Geld verschieben',
        'note_placeholder' => 'z. B. Umbuchung für den Urlaub',
    ],

    'withdraw' => [
        'heading' => 'Entnehmen aus :name',
        'note_placeholder' => 'z. B. Entnahme',
    ],

    'available_in' => 'Verfügbar in :name: :amount',

    'errors' => [
        'enter_name' => 'Gib dieser Rücklage einen Namen.',
        'select_account' => 'Wähle ein Konto für diese Rücklage.',
        'amount_exceeds_unallocated_available' => 'Der Betrag übersteigt den nicht zugeteilten Saldo (:amount verfügbar).',
        'amount_exceeds_pot_balance' => 'Der Betrag übersteigt den Saldo in :name (:amount verfügbar).',
        'generic' => 'Der Topf konnte nicht gespeichert werden. Prüfen Sie die Felder und versuchen Sie es erneut.',
        'amount_invalid' => 'Geben Sie einen Betrag größer als null ein.',
        'goal_already_linked' => 'Dieses Ziel hat bereits einen aktiven verknüpften Topf. Archivieren Sie ihn zuerst.',
        'account_cannot_hold_pots' => 'Eine Rücklage braucht ein Konto, auf dem Geld liegt. Wähle ein anderes Konto.',
        'select_target_pot' => 'Wähle eine Rücklage, in die verschoben werden soll.',
        'move_target_missing' => 'Diese Rücklage ist nicht mehr verfügbar. Wähle eine andere.',
        'move_same_pot' => 'Eine Rücklage kann kein Geld an sich selbst verschieben. Wähle eine andere Rücklage.',
        'move_cross_account' => 'Rücklagen tauschen Geld nur innerhalb eines Kontos, und :name liegt auf :account.',
        'pot_missing' => 'Diese Rücklage ist nicht mehr verfügbar.',
        'operation_failed' => 'Das hat nicht geklappt. Es wurde kein Geld verschoben — versuche es erneut.',
    ],

    'toast' => [
        'pot_created' => 'Rücklage angelegt.',
        'pot_updated' => 'Rücklage aktualisiert.',
        'pot_funded' => 'In die Rücklage eingezahlt.',
        'withdrawn' => 'Aus der Rücklage entnommen.',
        'funds_moved' => 'Geld verschoben.',
        'pot_archived' => 'Rücklage archiviert.',
        'pot_restored' => 'Rücklage wiederhergestellt.',
    ],
];
