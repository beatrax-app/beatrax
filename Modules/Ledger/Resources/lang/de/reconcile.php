<?php

declare(strict_types=1);

return [
    'page_title' => 'Abgleich',
    'heading' => 'Abgleich',
    'intro' => 'Prüfe den Saldo eines Kontoauszugs gegen deine bestätigten Transaktionen. Wenn beides übereinstimmt, schließe den Abgleich ab, um diese Zeilen zu sperren.',

    'account' => 'Konto',
    'choose_account' => 'Wähle ein Konto…',
    'statement_date' => 'Kontoauszugsdatum',
    'statement_balance' => 'Saldo laut Kontoauszug (:symbol)',
    'balance_help' => 'Wenn möglich aus deinem zuletzt importierten Kontoauszug vorausgefüllt — negativ bei Schulden, in beiden Fällen bearbeitbar.',

    'cleared_balance' => 'Bestätigter Saldo',
    'statement_target' => 'Sollwert laut Kontoauszug',
    'difference' => 'Differenz',

    'pill' => [
        'choose_account' => 'wähle ein Konto',
        'enter_balance' => 'gib einen Saldo laut Kontoauszug ein',
        'matched' => 'stimmt überein — :amount',
        'discrepancy' => 'Abweichung — :amount',
    ],

    'mismatch_html' => 'Der Saldo laut Kontoauszug stimmt noch nicht mit deinem bestätigten Saldo überein. Schalte bestätigte Zeilen in der <a href=":url" class="underline">Transaktionsliste</a> um oder passe den eingegebenen Saldo an, bis die Differenz null ist — dieser Ablauf legt nie eine Ausgleichsbuchung an.',

    'check' => 'Prüfen',
    'complete' => 'Abgleich abschließen',

    'errors' => [
        'choose_account' => 'Wähle zuerst ein Konto.',
        'invalid_balance_date' => 'Gib einen gültigen Saldo laut Kontoauszug und ein gültiges Datum ein.',
        'mismatch' => 'Der Saldo laut Kontoauszug stimmt noch nicht mit dem bestätigten Saldo überein — passe bestätigte Zeilen oder den eingegebenen Saldo an, bis die Differenz null ist.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Für dieses Kontoauszugsdatum gibt es nichts zu sperren.',
        'complete' => 'Abgleich abgeschlossen — :count Zeile gesperrt.|Abgleich abgeschlossen — :count Zeilen gesperrt.',
    ],
];
