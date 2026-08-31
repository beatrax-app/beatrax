<?php

declare(strict_types=1);

return [
    'page_title' => 'Import-Vorschau',
    'heading' => 'Import-Vorschau',
    'discard' => 'Import verwerfen',
    'confirm' => 'Import bestätigen',
    'subtitle' => 'Prüfe die eingelesenen Zeilen. Bis du bestätigst, wird nichts in deinem Hauptbuch gespeichert.',

    'already_imported' => 'Diese Datei wurde bereits importiert.',

    'already_imported_link' => 'Importergebnis ansehen',

    'expired_html' => 'Die Vorschau ist abgelaufen. <a href="/imports/new" class="underline">Lade die Datei erneut hoch</a>, um es noch einmal zu versuchen.',

    'save_name' => 'Namen speichern',
    'account_name_label' => 'Kontoname',
    'account_placeholder' => 'z. B. Hauptsparkonto',
    'rename_aria' => 'Diesen Zahlungspartner umbenennen',

    'unknown_iban_prefix' => 'Wir haben eine unbekannte IBAN gefunden:',

    'unknown_account_prefix' => 'Wir haben ein unbekanntes Konto gefunden:',
    'unknown_iban_suffix' => 'Gib diesem Konto einen Namen.',

    'ics' => [
        'name' => 'ICS-Karte',
        'heading' => 'Gib deinem ICS-Kartenkonto einen Namen.',
        'help' => 'Das ist das erste Mal, dass du ICS-Daten importierst. Gib dieser Karte einen Namen, damit sie überall in der App einheitlich auftaucht.',
        'placeholder' => 'z. B. ICS-Karte',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Gib deinem PayPal-Konto einen Namen.',
        'help' => 'Das ist das erste Mal, dass du PayPal-Daten importierst. Gib dieser Wallet einen Namen, damit sie überall in der App einheitlich auftaucht.',
        'placeholder' => 'z. B. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Gib deinem Google-Play-Konto einen Namen.',
        'help' => 'Das ist das erste Mal, dass du einen Google-Play-Beleg importierst. Gib diesem Konto einen Namen, damit es überall in der App einheitlich auftaucht.',
        'placeholder' => 'z. B. Google Play',
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

    'rows_shown' => 'Angezeigte Zeilen: :shown von :total',

    'show_more' => 'Mehr Zeilen anzeigen',

    'errors' => [
        'app_locked' => 'Entsperren Sie die App zum Importieren: Die Verschlüsselungsschlüssel können im gesperrten Zustand nicht verwendet werden.',
        'archive_holds_one_message' => 'Diese Datei ist eine einzelne E-Mail-Nachricht, kein Postfach-Archiv; als Archiv gelesen steht nichts darin. Laden Sie sie erneut hoch, mit dem Format E-Mail-Nachricht.',
        'email_file_is_an_archive' => 'Diese Datei ist ein Postfach-Archiv: Sie enthält mehr als eine Nachricht, und als einzelne Nachricht gelesen würde nur die erste übernommen. Laden Sie sie erneut hoch, mit dem Format Postfach-Archiv.',
        'file_stopped_short' => 'Die Kopfzeile passte, das Format ist also richtig. Das Lesen hörte vor dem Ende der Datei auf. Eine einzige unlesbare Zeile führt dazu, eine für dieses Gerät zu große Datei ebenfalls. Versuch es mit einem kürzeren Zeitraum.',
        'file_unreadable' => 'Diese Datei konnte nicht gelesen werden.',
        'file_unreadable_detail' => 'Die App konnte diese Datei nicht lesen (:code). Die vollständigen Angaben stehen im App-Protokoll; nennen Sie diesen Code, wenn Sie ein Problem melden.',
        'iban_not_in_preview' => 'Diese IBAN gehört nicht zur aktuellen Vorschau.',
        'not_an_email_file' => 'Diese Datei ist weder eine E-Mail-Nachricht noch ein Postfach-Archiv, darin ist also nichts als Beleg zu lesen. Wählen Sie den Importtyp und das Format, die zu Ihrer Datei passen.',
        'pdf_has_no_text_layer' => 'Dieses PDF enthält keinen Text — es ist ein Scan oder ein Foto eines Kontoauszugs, darin ist also nichts zu lesen. Laden Sie den Auszug selbst bei Ihrer Bank herunter oder nutzen Sie stattdessen einen CSV-Export.',
        'pdf_password_protected' => 'Dieses PDF ist mit einem Passwort geschützt, kein Leseprogramm kann es also öffnen. Speichern Sie in Ihrem PDF-Betrachter eine ungeschützte Kopie und importieren Sie diese.',
        'pdf_reader_unavailable' => 'Diese Version der App hat überhaupt keinen PDF-Leser, ein PDF-Kontoauszug lässt sich hier also nicht öffnen. Importieren Sie diese Datei auf einem anderen Gerät oder nutzen Sie stattdessen einen CSV-Export Ihrer Bank.',
        'row_belongs_to_another_statement' => 'Diese Zeile gehört zu einer Transaktion in einer anderen Auszugsdatei. Importieren Sie diesen Auszug ebenfalls — beide werden zusammen gelesen.',
        'row_unreadable' => 'Diese Zeile konnte nicht gelesen werden.',
        'row_unreadable_detail' => 'Die App konnte diese Zeile nicht lesen (:code). Die vollständigen Angaben stehen im App-Protokoll; nennen Sie diesen Code, wenn Sie ein Problem melden.',
        'unknown_account' => 'Diese Zeile gehört zu einem Konto, das du noch nicht benannt hast.',
    ],

    'receipts' => [
        'heading' => 'Diese Datei wurde als E-Mail gelesen',
        'saved' => 'Was sie enthielt, steht unten, und jede Nachricht ist gespeichert.',
        'none_imported' => 'Nichts davon wurde zu einer Transaktion, es kam also nichts in dein Hauptbuch.',
        'shown' => 'Angezeigte Nachrichten: :shown von :total',
        'no_subject' => 'Kein Betreff',

        'state' => [
            'read' => 'Als Zahlung gelesen — bestätige diesen Import, damit sie in dein Hauptbuch kommt.',
            'not_a_payment' => 'Keine Zahlung. Diese Nachricht kündigt etwas an, statt eine Zahlung zu bestätigen.',
            'unreadable' => 'Gespeichert. Die App liest Belege dieses Absenders, fand in dieser Nachricht aber weder Betrag noch Händler noch Referenz.',
            'unknown_sender' => 'Gespeichert. Die App liest keine Belege dieses Absenders und hat der Nachricht daher nichts entnommen.',
        ],
    ],

    'failed' => [
        'heading' => 'Diese Datei konnte nicht gelesen werden',
        'no_rows' => 'In dieser Datei wurden keine Transaktionen gefunden, es gibt also nichts zu importieren.',
        'nothing_read' => 'Nichts in dieser Datei konnte als Transaktion gelesen werden, es gibt also nichts zu importieren.',
        'every_row' => 'Keine einzige Zeile dieser Datei konnte gelesen werden, es gibt also nichts zu importieren. Jede Zeile steht unten mit dem Grund.',
        'likely_cause' => 'Meist passt die Kopfzeile nicht zur gewählten Quelle. Prüfe Bank und Format im Upload-Bildschirm oder lade den Kontoauszug erneut bei deiner Bank herunter.',
        'truncated_heading' => 'Nur ein Teil dieser Datei konnte gelesen werden',
        'truncated' => 'Das Einlesen wurde mitten in der Datei abgebrochen. Alles danach wurde nicht gelesen und wird nicht importiert.',
        'some_rows' => 'Einige Zeilen konnten nicht gelesen werden. Sie sind unten markiert und werden übersprungen; das Bestätigen importiert die übrigen.',
        'detail_label' => 'Meldung des Parsers:',
        'rows_read_label' => 'Gelesene Zeilen',
        'rows_skipped_label' => 'Übersprungene Zeilen',
    ],
];
