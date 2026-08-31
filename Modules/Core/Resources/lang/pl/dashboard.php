<?php

declare(strict_types=1);

return [
    'page_title' => 'Pulpit',
    'subtitle' => 'Ten okres w skrócie.',

    'previous_period' => 'Poprzedni okres',
    'today' => 'Dziś',
    'next_period' => 'Następny okres',

    'totals_aria' => 'Sumy w tym okresie',
    'totals_aria_currency' => 'Sumy w tym okresie — :currency',
    'in' => 'Wpływy',
    'out' => 'Wydatki',
    'net' => 'Netto',

    'status_tiles_aria' => 'Kafelki statusu',
    'email_scan_health' => 'Stan skanowania poczty — połączone: :count skrzynka|Stan skanowania poczty — połączone: :count skrzynki|Stan skanowania poczty — połączone: :count skrzynek',

    'top_spending' => 'Największe wydatki',
    'no_expenses' => 'Brak skategoryzowanych wydatków.',
    'top_spending_refunded' => 'Poza rankingiem — :amount wróciło',

    'recent_transactions' => 'Ostatnie transakcje',
    'view_all' => 'Zobacz wszystkie',
    'nothing_period' => 'Nic w tym okresie.',
    'th_date' => 'Data',
    'th_counterparty' => 'Kontrahent',
    'th_category' => 'Kategoria',
    'th_amount' => 'Kwota',
    'uncategorized' => 'Bez kategorii',

    'jump_to_records' => [
        'body' => 'Nic w tym okresie. Twoje najnowsze transakcje nadal tu są.',
        'action' => 'Pokaż okres :period',
    ],

    'reauth' => [
        'title' => 'Skrzynka wymaga ponownego połączenia.',
        'body' => 'Co najmniej jedna skrzynka została wylogowana — Beatrax nie może jej skanować, dopóki nie połączysz jej ponownie.',
        'link' => 'Przejdź do skrzynek',
        'dismiss' => 'Odrzuć',
    ],

    'failed_chain' => [
        'title' => 'Rozwiązywanie łańcuchów nie powiodło się.',
        'body' => 'W co najmniej jednym zadaniu rozwiązywania łańcuchów wystąpił błąd.',
        'link' => 'Otwórz inspektor kolejki',
    ],
];
