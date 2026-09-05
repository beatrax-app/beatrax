<?php

declare(strict_types=1);

return [
    'page_title' => 'Triage der Zahlungspartner',
    'heading' => 'Triage unbekannter Zahlungspartner',

    'progress' => ':seen von :total · :percent % · noch ~:minutes Min.',
    'progress_aria' => 'Fortschritt der Triage',

    'all_caught_aria' => 'Alle Zahlungspartner gekennzeichnet',
    'all_caught_heading' => '🎉 Alles erledigt — jeder Zahlungspartner ist gekennzeichnet.',
    'back_to_index' => 'Zurück zu den Zahlungspartnern →',

    'meta' => ':count Transaktion · zuletzt gesehen :date|:count Transaktionen · zuletzt gesehen :date',

    'suggested_aria' => 'Vorgeschlagene Übereinstimmung',
    'suggestion_medium' => '✨ Vielleicht **:name** — mittlere Zuverlässigkeit',
    'suggestion_low' => 'Mustertreffer: **:name** — geringe Zuverlässigkeit. Prüfe das, bevor du verknüpfst.',
    'suggestion_high' => '✨ Sieht aus wie **:name** — hohe Zuverlässigkeit',

    'reasoning' => ':hits von :total kürzlicher Buchung auf dieser IBAN verweist auf :name.|:hits von :total kürzlichen Buchungen auf dieser IBAN verweisen auf :name.',
    'yes_link' => 'Ja, mit :name verknüpfen ↵',
    'no_not' => 'Nein, nicht :name',

    'recent_on_iban' => 'Letzte Transaktionen auf dieser IBAN',
    'recent_on_counterparty' => 'Letzte Transaktionen mit dieser Gegenpartei',
    'no_transactions_yet' => 'Noch keine Transaktionen erfasst.',

    'label_manually' => 'Oder manuell kennzeichnen',
    'label_question' => 'Was ist dieser Zahlungspartner?',
    'display_name_label' => 'Anzeigename',
    'type_label' => 'Typ',
    'type_merchant' => 'Händler',
    'type_personal' => 'Privat',
    'type_bank' => 'Bank',
    'type_government' => 'Behörde',
    'save_label' => 'Kennzeichnung speichern',
    'name_required' => 'Gib diesem Zahlungspartner zuerst einen Namen.',
    'draft_kept' => 'Deine Eingabe bleibt erhalten, während du dich durch die Liste bewegst.',

    'skip' => 'Vorerst überspringen',
    'mark_ignored' => 'Nicht mehr danach fragen',
    'skip_note' => 'Überspringen schreibt nichts — es geht nur zum nächsten Unbekannten weiter.',
    'mark_ignored_note' => 'Damit wird der Zahlungspartner als ignoriert markiert und bleibt aus dieser Liste heraus. Name, Typ und Verlauf bleiben unberührt, und du kannst ihn später auf der Seite Zahlungspartner kennzeichnen.',
    'previous' => 'Vorheriger Unbekannter',

    'kbd_yes' => 'ja',
    'kbd_no' => 'nein',
    'kbd_skip' => 'überspringen',
    'kbd_next' => 'weiter',

    'footer' => ':seen bereits gekennzeichnet · noch :count',
];
