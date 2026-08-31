<?php

declare(strict_types=1);

return [
    'page_title' => 'Uzgadnianie',
    'heading' => 'Uzgadnianie',
    'intro' => 'Potwierdź saldo wyciągu konta wobec swoich rozliczonych transakcji. Gdy się zgadzają, zakończ uzgadnianie, aby zablokować te wiersze.',

    'account' => 'Konto',
    'choose_account' => 'Wybierz konto…',
    'statement_date' => 'Data wyciągu',
    'statement_balance' => 'Saldo wyciągu (:symbol)',
    'balance_help' => 'Wypełniane wstępnie z ostatniego zaimportowanego wyciągu, o ile jest dostępny — ujemne dla zadłużenia, w obu przypadkach edytowalne.',

    'cleared_balance' => 'Saldo rozliczone',
    'statement_target' => 'Cel z wyciągu',
    'difference' => 'Różnica',

    'pill' => [
        'choose_account' => 'wybierz konto',
        'choose_date' => 'wybierz datę wyciągu',
        'enter_balance' => 'wpisz saldo wyciągu',
        'matched' => 'zgadza się — :amount',
        'discrepancy' => 'rozbieżność — :amount',
        'reconciled_through' => 'uzgodnione do :date',
    ],

    'mismatch_html' => 'Saldo wyciągu jeszcze nie zgadza się z saldem rozliczonym. Przełączaj rozliczone wiersze na <a href=":url" class="underline">liście transakcji</a> albo popraw wpisane saldo, aż różnica osiągnie zero — ten proces nigdy nie tworzy wpisu wyrównującego.',
    'unreachable_no_baseline_html' => 'Żadna kombinacja wierszy nie sprowadzi tej różnicy do zera. To konto nie ma zapisanego salda otwarcia, więc jego saldo liczone jest od zera. Zaimportuj wyciąg, którym konto się otwiera, albo ustaw saldo otwarcia w <a href=":url" class="underline">Ustawieniach</a>.',
    'unreachable' => 'Żadna kombinacja wierszy nie sprowadzi tej różnicy do zera: leży poza zakresem wszystkich wierszy na tym koncie do podanej daty. Sprawdź datę wyciągu i wprowadzone saldo.',

    'check' => 'Sprawdź',
    'complete' => 'Zakończ uzgadnianie',
    'complete_unavailable' => 'Do tej daty nie ma już czego zablokować — oznacz kolejne wiersze jako rozliczone lub wybierz późniejszą datę wyciągu.',

    'errors' => [
        'choose_account' => 'Najpierw wybierz konto.',
        'invalid_balance_date' => 'Podaj prawidłowe saldo wyciągu i datę.',
        'mismatch' => 'Saldo wyciągu jeszcze nie zgadza się z saldem rozliczonym — popraw rozliczone wiersze lub wpisane saldo, aż różnica wyniesie zero.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Nie ma czego zablokować dla tej daty wyciągu.',
        'complete' => 'Uzgadnianie zakończone — zablokowano :count wiersz.|Uzgadnianie zakończone — zablokowano :count wiersze.|Uzgadnianie zakończone — zablokowano :count wierszy.',
    ],
];
