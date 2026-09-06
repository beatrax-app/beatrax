<?php

declare(strict_types=1);

return [
    'page_title' => 'Regeln',
    'heading' => 'Regeln',
    'intro' => 'Transaktionen schon beim Import kategorisieren. Regeln gelten für jede Quelle — Bank, Karte, PayPal und E-Mail-Belege.',
    'device_local_note' => 'Regeln bleiben auf diesem Gerät. Sie werden nicht mit deinen anderen Geräten geteilt.',

    'reapply' => 'Regeln erneut auf den Verlauf anwenden',
    'reapply_confirm' => 'Alle Regeln erneut auf deinen gesamten Verlauf anwenden? Jede Kategorie, jeder Zahlungspartner, jede Notiz und jede Steuer-Markierung, die eine Regel gesetzt hat, wird überschrieben. Was du von Hand gesetzt hast, bleibt, und ebenso alles auf einem abgeglichenen Kontoauszug oder auf einer Transaktion, die du aufgeteilt hast. Die alten Werte holt nichts zurück.',
    'reapplying' => 'Wird erneut angewendet…',
    'new_rule' => 'Neue Regel',

    'reapply_progress' => 'Regeln werden erneut angewendet… :checked von :count Transaktion geprüft|Regeln werden erneut angewendet… :checked von :count Transaktionen geprüft',

    'empty_heading' => 'Noch keine Regeln',
    'empty_body' => 'Regeln prüfen Transaktionen anhand mehrerer Bedingungen und übernehmen Änderungen an Kategorie, Zahlungspartner und Notiz automatisch beim Import. Eine geänderte Steuer-Markierung greift, wenn du die Regeln erneut auf deinen bestehenden Verlauf anwendest.',
    'empty_cta' => 'Erste Regel erstellen',

    'col_priority' => 'Priorität',
    'col_conditions' => 'Bedingungen',
    'col_actions' => 'Aktionen',
    'col_hits' => 'Treffer',
    'col_created' => 'Erstellt',
    'col_row_actions' => 'Aktionen',
    'inactive_badge' => 'Aus',
    'combinator_all' => 'ALLE',
    // i18n-review: de · combinator_any — EINE is the quantifier rule_form.match_any
    // chose, read here as a logic chip beside ALLE. BELIEBIG is the alternative a
    // native reader may prefer.
    'combinator_any' => 'EINE',
    'inactive_title' => 'Diese Regel läuft nicht. Eine Regel schaltet sich ab, wenn die Kategorie oder Gegenpartei, auf die sie verweist, gelöscht wird.',

    'more_conditions' => '+:count weitere',

    'delete_confirm' => 'Löschen?',
    'delete_yes' => 'Ja, löschen',
    'cancel' => 'Abbrechen',
    'edit' => 'Bearbeiten',
    'delete' => 'Löschen',
    'edit_aria' => 'Regel bearbeiten (Priorität :priority)',
    'delete_aria' => 'Regel löschen (Priorität :priority)',

    'footer_note' => 'Regeln und Zahlungspartner-Verlauf arbeiten zusammen. Wenn du eine Regel löschst, wird nicht gelöscht, was Beatrax aus früheren Kategorisierungen gelernt hat — beim nächsten Import kann dieselbe Kategorie weiterhin aus dem Verlauf vorgeschlagen werden.',

    'chip_category' => 'Kategorie: :path',
    'chip_counterparty' => 'Zahlungspartner: :path',
    'chip_note' => 'Notiz',
    'chip_tax_tag' => 'Steuer-Markierung',

    'flash_deleted' => 'Regel gelöscht.',
    'flash_not_found' => 'Regel nicht gefunden (sie wurde möglicherweise in einem anderen Tab gelöscht).',
    'flash_saved' => 'Regel gespeichert.',
    'flash_reapplying' => 'Regeln werden erneut auf deinen Verlauf angewendet…',
    'summary_no_changes' => 'Keine Änderungen — dein Verlauf entspricht bereits deinen Regeln.',
    'summary_updated' => ':fields in :transactions aktualisiert.',
    'summary_fields' => ':count Feld|:count Felder',
    'summary_transactions' => ':count Transaktion|:count Transaktionen',
    'summary_reconciled_skipped' => ':count abgeglichene Transaktion wurde übersprungen.|:count abgeglichene Transaktionen wurden übersprungen.',
];
