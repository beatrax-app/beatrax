<?php

declare(strict_types=1);

return [
    'page_title' => 'Zahlungspartner',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Zahlungspartner',

    'edit_display_name' => 'Anzeigenamen bearbeiten',

    'hero_net_received' => 'Netto erhalten',
    'hero_12mo_total' => 'Summe 12 Monate',
    'hero_transactions' => 'Transaktionen',
    'hero_first_seen' => 'Zuerst gesehen',

    'tabs' => [
        'overview' => 'Übersicht',
        'transactions' => 'Transaktionen',
        'chains' => 'Ketten',
        'aliases' => 'Aliase',
        'transfers' => 'Überweisungen',
        'entries' => 'Einträge',
        'payments' => 'Zahlungen',
        'tax_years' => 'Steuerjahre',
    ],

    'tablist_aria' => 'Abschnitte des Zahlungspartners',

    'tab_note_personal' => '— keine Finanzierungsketten für private Kontakte',
    'tab_note_bank' => '— ein Zahlungspartner für Bankgebühren erzeugt keine Finanzierungsketten',
    'tab_note_government' => '— keine Finanzierungsketten für behördliche Zahlungspartner',

    'recent_activity' => 'Letzte Aktivität',
    'recurring' => 'Wiederkehrend',
    'uncategorized' => 'Nicht kategorisiert',
    'no_recent_transactions' => 'Für diesen Zahlungspartner sind noch keine Transaktionen erfasst.',
    'see_all' => 'Alle :count anzeigen →',

    'bank' => [
        'fees_heading' => 'Bankgebühren nach Kategorie',
        'no_fees' => 'Für diesen Zahlungspartner sind noch keine Gebühren erfasst.',
    ],

    'government' => [
        'intro' => 'Jahresaufschlüsselung über alle Jahre mit Aktivität. Das laufende Jahr ist hervorgehoben.',
        'no_payments' => 'Für diesen Zahlungspartner sind noch keine Zahlungen erfasst.',
    ],

    'merchant' => [
        'categories' => 'Kategorien',

        'categories_empty_html' => 'Noch keine Kategorien — Transaktionen ohne Kategorie erscheinen in der <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategorisierung</a>.',
        'no_recurring' => 'Keine wiederkehrenden Muster erkannt.',
        'per_month_suffix' => '/Mon.',
        'funding_chain' => 'Finanzierungskette',
        'no_funding_chain' => 'Noch keine Finanzierungskette erkannt. Für die Auflösung der Finanzierungskette sind Importe von ASN- und PayPal-Daten nötig.',
        'open_chains' => 'Kettenprüfung öffnen →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Markierung hinzufügen',
        'no_recurring' => 'Kein wiederkehrendes Muster erkannt — private Überweisungen folgen selten einem festen Rhythmus; selbst regelmäßige Mietanteile können sich im Datum verschieben.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Dieser Zahlungspartner ist noch nicht gekennzeichnet',
        'not_labelled_body' => 'Unbekannte zu kennzeichnen hilft dem Dashboard, genaue Monatssummen und Finanzierungsketten anzuzeigen.',
        'label_cta' => 'Diesen Zahlungspartner kennzeichnen',
    ],

    'support' => [
        'contact_help' => 'Kontakt & Hilfe',
        'sign_in_apply' => 'Anmelden · beantragen',
        'your_rights' => 'Deine Rechte · widersprechen',
        'cancel' => 'Kündigen',
        'help_support' => 'Hilfe & Support',
        'cheaper_plan' => 'Günstigerer Tarif',
        'aria_gov' => 'Hilfe bekommen',
        'aria_merchant' => 'Support und Kündigung',
        'heading_gov' => 'Hilfe bekommen',
        'heading_merchant' => 'Support & Kündigung',
        'cancel_by_email' => 'Per E-Mail kündigen',
    ],
];
