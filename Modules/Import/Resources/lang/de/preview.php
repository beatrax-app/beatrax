<?php

declare(strict_types=1);

return [
    'page_title' => 'Import-Vorschau',
    'heading' => 'Import-Vorschau',
    'discard' => 'Import verwerfen',
    'confirm' => 'Import bestätigen',
    'subtitle' => 'Prüfe die eingelesenen Zeilen. Bis du bestätigst, wird nichts in deinem Hauptbuch gespeichert.',

    'expired_html' => 'Die Vorschau ist abgelaufen. <a href="/imports/new" class="underline">Lade die Datei erneut hoch</a>, um es noch einmal zu versuchen.',

    'save_name' => 'Namen speichern',
    'account_name_label' => 'Kontoname',
    'account_placeholder' => 'z. B. Hauptsparkonto',
    'rename_aria' => 'Diesen Zahlungspartner umbenennen',

    'unknown_iban_prefix' => 'Wir haben eine unbekannte IBAN gefunden:',
    'unknown_iban_suffix' => 'Gib diesem Konto einen Namen.',

    'ics' => [
        'heading' => 'Gib deinem ICS-Kartenkonto einen Namen.',
        'help' => 'Das ist das erste Mal, dass du ICS-Daten importierst. Gib dieser Karte einen Namen, damit sie überall in der App einheitlich auftaucht.',
        'placeholder' => 'z. B. ICS-Karte',
    ],

    'paypal' => [
        'heading' => 'Gib deinem PayPal-Konto einen Namen.',
        'help' => 'Das ist das erste Mal, dass du PayPal-Daten importierst. Gib dieser Wallet einen Namen, damit sie überall in der App einheitlich auftaucht.',
        'placeholder' => 'z. B. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Finanzierungsquelle',
    'col_counterparty' => 'Zahlungspartner',
    'col_amount' => 'Betrag',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Neu',
        'new_title' => 'Wird deinem Hauptbuch hinzugefügt.',
        'duplicate' => 'Duplikat',
        'duplicate_title' => 'Bereits importiert — wird übersprungen.',
        'enriched' => 'Angereichert',
        'enriched_title' => 'Bestehende Zeile wird mit einer stärkeren Quellreferenz aktualisiert.',
        'error' => 'Fehler',
    ],

    'chain' => [
        'heading' => 'Ketten werden aufgelöst…',
        'pending' => 'In der Warteschlange. Der Ketten-Resolver startet gleich.',
        'running' => 'Finanzierungsketten werden verknüpft und Kontoauszugsabrechnungen zerlegt.',
        'failed_prefix' => 'Auflösen der Ketten fehlgeschlagen:',
        'unknown_error' => 'ein unbekannter Fehler ist aufgetreten',
        'open_horizon' => 'Horizon öffnen',
        'failed_suffix' => 'zum erneuten Versuch oder zur Prüfung.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Diese IBAN gehört nicht zur aktuellen Vorschau.',
    ],
];
