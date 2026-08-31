<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Typ des Zahlungspartners: :type',
        'merchant' => 'Händler',
        'personal' => 'Privat',
        'bank' => 'Bank',
        'government' => 'Behörde',
        'self' => 'Eigen',
        'unknown' => 'Unbekannt',
    ],

    'filter_chips' => [
        'aria' => 'Nach Typ filtern',
        'all' => 'Alle',
        'merchant' => 'Händler',
        'personal' => 'Privat',
        'bank' => 'Banken',
        'government' => 'Behörden',
        'self' => 'Eigen',
        'unknown' => 'Unbekannt',
    ],

    'default_name' => [
        'bank_fee' => 'Bankgebühr',
    ],

    'cp_card' => [
        'aria' => 'Zahlungspartner: :name',
        'recent_aria' => 'Letzte Aktivität',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finanzierungskette: ',
        'join' => ' zu ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN ausgeblendet — klicke auf IBAN anzeigen, um sie einzublenden',
        // i18n-review: de · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN ausgeblendet — tippe auf IBAN anzeigen, um sie einzublenden',
        'show' => 'IBAN anzeigen',
        'hide' => 'IBAN ausblenden',
    ],

    'privacy_banner' => [
        'aria' => 'Datenschutzhinweis für privaten Kontakt',
        'body' => '🔒 Das ist ein privater Kontakt. IBAN und persönliche Daten sind standardmäßig ausgeblendet und werden nie in Exporte übernommen.',
    ],

    'self_stub' => [
        'aria' => 'Kein echter Zahlungspartner',
        'heading' => 'Das ist eigentlich kein Zahlungspartner',

        'body_rest_html' => ' erscheint hier, weil es in deinen Transaktionen als Finanzierungsglied zwischen Konten auftaucht. Aber es ist <strong>dein eigenes Konto</strong> und niemand, mit dem du Geschäfte machst.',
        'body2' => 'Öffne die Kontoansicht für Saldo, Kontoauszüge und den vollständigen Transaktionsverlauf.',
        'open_cta' => 'Kontoansicht :name öffnen →',
        'hide_cta' => 'Aus dieser Liste ausblenden',
        'recent_legs' => 'Letzte Bewegungen zwischen Konten',
    ],
];
