<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontrahent',
    'fallback_account' => 'Konto',
    'fallback_counterparty' => 'Kontrahent',

    'edit_display_name' => 'Edytuj nazwę wyświetlaną',

    'hero_net_received' => 'Otrzymano netto',
    'hero_12mo_total' => 'Suma z 12 miesięcy',
    'hero_transactions' => 'Transakcje',
    'hero_first_seen' => 'Pierwsze wystąpienie',

    'tabs' => [
        'overview' => 'Przegląd',
        'transactions' => 'Transakcje',
        'chains' => 'Łańcuchy',
        'aliases' => 'Aliasy',
        'transfers' => 'Przelewy',
        'entries' => 'Wpisy',
        'payments' => 'Płatności',
        'tax_years' => 'Lata podatkowe',
    ],

    'tablist_aria' => 'Sekcje kontrahenta',

    'tab_note_personal' => '— brak łańcuchów finansowania dla kontaktów osobistych',
    'tab_note_bank' => '— kontrahent typu opłaty bankowe nie tworzy łańcuchów finansowania',
    'tab_note_bank_institution' => '— brak łańcuchów finansowania dla kontrahentów instytucjonalnych',
    'tab_note_government' => '— brak łańcuchów finansowania dla instytucji publicznych',

    'recent_activity' => 'Ostatnia aktywność',
    'recurring' => 'Cykliczne',
    'uncategorized' => 'Bez kategorii',
    'no_recent_transactions' => 'Brak zapisanych transakcji dla tego kontrahenta.',
    'see_all' => 'Zobacz wszystkie (:count) →',

    'bank' => [
        'fees_heading' => 'Opłaty bankowe według kategorii',
        'activity_heading' => 'Aktywność według kategorii',
        'no_fees' => 'Brak zapisanych opłat dla tego kontrahenta.',
    ],

    'government' => [
        'intro' => 'Roczne zestawienie za wszystkie lata z aktywnością. Bieżący rok jest wyróżniony.',
        'no_payments' => 'Brak zapisanych płatności dla tego kontrahenta.',
    ],

    'merchant' => [
        'categories' => 'Kategorie',

        'categories_empty_html' => 'Brak kategorii — transakcje bez kategorii pojawiają się w sekcji <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Kategoryzacja</a>.',
        'no_recurring' => 'Nie wykryto wzorców cyklicznych.',
        'per_month_suffix' => '/mies.',
        'funding_chain' => 'Łańcuch finansowania',
        'no_funding_chain' => 'Nie wykryto jeszcze łańcucha finansowania. Do rozwiązania łańcucha potrzebny jest import danych ASN + PayPal.',
        'open_chains' => 'Otwórz przegląd łańcuchów →',
    ],

    'personal' => [
        'contact' => 'Kontakt',
        'add_tag' => '+ Dodaj tag',
        'no_recurring' => 'Nie wykryto cykliczności — przelewy prywatne rzadko trzymają się stałego rytmu; nawet regularny podział czynszu może zmieniać daty.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Ten kontrahent nie ma jeszcze etykiety',
        'not_labelled_body' => 'Opisanie nieznanych kontrahentów pomaga Pulpitowi pokazywać dokładne sumy miesięczne i łańcuchy finansowania.',
        'label_cta' => 'Opisz tego kontrahenta',
    ],

    'support' => [
        'contact_help' => 'Kontakt i pomoc',
        'sign_in_apply' => 'Zaloguj się · złóż wniosek',
        'your_rights' => 'Twoje prawa · sprzeciw',
        'cancel' => 'Zrezygnuj',
        'help_support' => 'Pomoc i wsparcie',
        'cheaper_plan' => 'Tańszy plan',
        'aria_gov' => 'Uzyskiwanie pomocy',
        'aria_merchant' => 'Wsparcie i rezygnacja',
        'heading_gov' => 'Uzyskiwanie pomocy',
        'heading_merchant' => 'Wsparcie i rezygnacja',
        'cancel_by_email' => 'Zrezygnuj e-mailem',
        'withheld' => 'link nieudostępniony',
        'notes_language' => 'W języku :language, tak jak publikuje to dostawca.',
    ],
];
