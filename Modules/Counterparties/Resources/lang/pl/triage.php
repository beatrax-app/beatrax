<?php

declare(strict_types=1);

return [
    'page_title' => 'Porządkowanie kontrahentów',
    'heading' => 'Uporządkuj nieznanych kontrahentów',

    'progress' => ':seen z :total · :percent% · pozostało ~:minutes min',
    'progress_aria' => 'Postęp porządkowania',

    'all_caught_aria' => 'Wszyscy kontrahenci opisani',
    'all_caught_heading' => '🎉 Wszystko gotowe — każdy kontrahent ma etykietę.',
    'back_to_index' => 'Wróć do kontrahentów →',

    'meta' => ':count transakcja · ostatnio widziano :date|:count transakcje · ostatnio widziano :date|:count transakcji · ostatnio widziano :date',

    'suggested_aria' => 'Sugerowane dopasowanie',
    'suggestion_medium' => '✨ Może **:name** — pewność średnia',
    'suggestion_low' => 'Dopasowanie wzorca: **:name** — pewność niska. Sprawdź przed powiązaniem.',
    'suggestion_high' => '✨ Wygląda na **:name** — pewność wysoka',

    'reasoning' => ':hits z :total ostatniej transakcji na tym IBAN-ie wskazuje na :name.|:hits z :total ostatnich transakcji na tym IBAN-ie wskazuje na :name.|:hits z :total ostatnich transakcji na tym IBAN-ie wskazuje na :name.',
    'yes_link' => 'Tak, powiąż z: :name ↵',
    'no_not' => 'Nie, to nie :name',

    'recent_on_iban' => 'Ostatnie transakcje na tym IBAN-ie',
    'recent_on_counterparty' => 'Ostatnie transakcje z tym kontrahentem',
    'no_transactions_yet' => 'Brak zapisanych transakcji.',

    'label_manually' => 'Albo opisz ręcznie',
    'label_question' => 'Czym jest ten kontrahent?',
    'display_name_label' => 'Nazwa wyświetlana',
    'type_label' => 'Typ',
    'type_merchant' => 'Sprzedawca',
    'type_personal' => 'Osoba prywatna',
    'type_bank' => 'Bank',
    'type_government' => 'Instytucja publiczna',
    'save_label' => 'Zapisz etykietę',
    'name_required' => 'Najpierw nadaj temu kontrahentowi nazwę.',
    'draft_kept' => 'To, co wpiszesz, zostaje zachowane, gdy przechodzisz przez kolejkę.',

    'skip' => 'Pomiń na razie',
    'mark_ignored' => 'Nie pytaj już o tego',
    'skip_note' => 'Pominięcie nic nie zapisuje — po prostu przechodzi do następnego nieznanego.',
    'mark_ignored_note' => 'To oznacza kontrahenta jako pominiętego, więc znika z tej kolejki. Jego nazwa, typ i historia pozostają nietknięte, a nadal możesz go opisać później na stronie Kontrahenci.',
    'previous' => 'Poprzedni nieznany',

    'kbd_yes' => 'tak',
    'kbd_no' => 'nie',
    'kbd_skip' => 'pomiń',
    'kbd_next' => 'dalej',

    'footer' => 'Opisano: :seen · pozostało: :count',
];
