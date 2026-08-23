<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-Exporte enthalten keine Saldozeilen, lege das also manuell fest.',
    'help_asn' => 'Automatisch anhand deines letzten Kontoauszugs verankert. Überschreibe das nur, wenn du weißt, dass der tatsächliche Saldo abweicht.',
    'help_default' => 'Überschreibe das nur, wenn du weißt, dass der tatsächliche aktuelle Saldo von dem abweicht, was Beatrax berechnet.',

    'legend' => 'Anfangssaldo der Prognose für :name',
    'opening_label' => 'Anfangssaldo',
    'opening_placeholder' => 'z. B. 1.250,00',
    'as_of_label' => 'Anfangssaldo per',
    'as_of_help' => 'Das Datum, für das der Betrag oben gilt.',

    'divergence' => 'Das weicht um mehr als €500 von dem Saldo ab, den Beatrax aus deinen importierten Transaktionen berechnet. Bist du sicher?',
    'use_beatrax' => 'Zahl von Beatrax verwenden',
    'use_mine' => 'Meine Zahl verwenden',

    'save' => 'Anfangssaldo speichern',
    'remove' => 'Anfangssaldo entfernen',
    'saved' => 'Gespeichert.',
    'removed' => 'Entfernt.',

    'toast' => [
        'updated' => 'Anfangssaldo aktualisiert.',
        'removed' => 'Anfangssaldo entfernt.',
    ],

    'errors' => [
        'invalid_number' => 'Der Anfangssaldo muss eine gültige Zahl sein.',
        'date_required' => 'Wähle das Datum, für das dieser Anfangssaldo gilt.',
        'date_invalid' => 'Das Datum des Anfangssaldos muss ein gültiges ISO-Datum sein (YYYY-MM-DD).',
        'date_future' => 'Das Datum des Anfangssaldos darf nicht in der Zukunft liegen.',
    ],
];
