<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcja',
    'heading' => 'Transakcja',
    'booked_on' => 'Zaksięgowano :date',

    'counterparty' => 'Kontrahent',
    'description' => 'Opis',
    'amount_native' => 'Kwota (waluta pierwotna)',
    'amount_settled' => 'Kwota (rozliczona)',
    'effective_rate' => 'Kurs efektywny',
    'ics_markup' => 'Zawiera ewentualną marżę ICS.',

    'split' => [
        'category' => 'Kategoria',
        'open' => 'Podziel na kategorie',
        'heading' => 'Podział na kategorie',
        'total' => 'Razem :amount',
        'tax_per_category' => 'Znaczniki podatkowe ustawia się poniżej osobno dla każdej kategorii.',
        'choose_category' => 'Wybierz kategorię',
        'note_label' => 'Notatka',
        'note_placeholder' => 'Notatka (opcjonalnie)',
        'tax_deductible' => 'Odliczane od podatku',
        'remove_leg_aria' => 'Usuń tę kategorię',
        'remove_leg_caption' => 'Usuń',
        'add_category' => '+ Dodaj kategorię',
        'soft_cap' => ':count z ~20 kategorii — rozważ zgrupowanie drobnych kwot.',
        'remaining_zero' => 'Pozostało :amount ✓',
        'remaining_to_assign' => 'Pozostało do przypisania: :amount',
        'over_allocated' => 'Przydzielono za dużo o :amount — zmniejsz jedną pozycję.',
        'save' => 'Zapisz podział',
        'saving' => 'Zapisywanie…',
        'unsplit' => 'Cofnij podział transakcji',
        'remove_to_one' => 'Po usunięciu zostanie jedna kategoria — :category.',
        'remove_to_one_fallback' => 'ta kategoria',
        'remove_category' => 'Usuń kategorię',
        'keep_category' => 'Zachowaj tę kategorię',
        'restore_single' => 'Przywrócić jako pojedynczą kategorię?',
        'survivor_legend' => 'Kategoria do zachowania',
        'confirm_unsplit' => 'Tak, cofnij podział',
        'keep_split' => 'Zachowaj podział',
    ],

    'tax' => [
        'section_aria' => 'Znacznik podatkowy',
        'label' => 'Odliczane od podatku',
    ],

    'reclassify' => [
        'heading' => 'Zmień klasyfikację',
        'help' => 'Zastąp wykryty typ. Jeśli ta transakcja jest sparowana z inną, wybór typu innego niż przelew rozparuje obie strony.',
        'choose_aria' => 'Wybierz nowy typ transakcji',
        'choose_option' => 'Wybierz typ…',
        'save' => 'Zapisz',
    ],

    'type_label' => [
        'expense' => 'Wydatek',
        'income' => 'Przychód',
        'transfer_out' => 'Przelew wychodzący',
        'transfer_in' => 'Przelew przychodzący',
        'fee' => 'Opłata',
        'refund' => 'Zwrot',
        'adjustment' => 'Korekta',
    ],

    'note' => [
        'heading' => 'Notatka',
        'help' => 'Osobista notatka do tej transakcji. Widoczna tylko dla Ciebie.',
        'label' => 'Notatka',
        'placeholder' => 'Dodaj notatkę…',
        'save' => 'Zapisz notatkę',
        'saved' => 'Zapisano',
    ],

    'reassign' => [
        'heading' => 'Zmień kontrahenta',
        'help' => 'Zastąp rozpoznanego kontrahenta dla tej transakcji.',
        'choose_aria' => 'Wybierz kontrahenta',
        'choose_option' => 'Wybierz kontrahenta…',
        'submit' => 'Zmień',
    ],

    'goal' => [
        'heading' => 'Cel oszczędnościowy',
        'help' => 'Zalicz tę transakcję do jednego ze swoich celów oszczędnościowych.',
        'choose_aria' => 'Wybierz cel oszczędnościowy',
        'choose_option' => 'Wybierz cel…',
        'submit' => 'Dodaj do celu',
        'remove_aria' => 'Usuń :name',
    ],

    'delete' => [
        'heading' => 'Usuń transakcję',
        'help' => 'Trwale usuwa tę transakcję. Tej operacji nie można cofnąć.',
        'button' => 'Usuń',
        'confirm_prompt' => 'Usunąć tę transakcję? Znikną z nią notatka, podział i znaczniki podatkowe.',
        'confirm' => 'Tak, usuń',
        'cancel' => 'Anuluj',
    ],

    'chain' => [
        'view' => 'Zobacz łańcuch',
    ],

    'unreconcile' => [
        'heading' => 'Uzgodniona i zablokowana',
        'help' => 'Zakończone uzgodnienie zablokowało tę transakcję. Jej kategoria, notatka, podział i znaczniki podatkowe pozostają bez zmian, dopóki jej nie odblokujesz.',
        'button' => 'Odblokuj do edycji',
        'confirm_question' => 'Odblokować tę transakcję do edycji? Nic się w niej nie zmienia, a kolejne zakończone uzgodnienie zablokuje ją ponownie.',
        'cancel' => 'Zostaw zablokowaną',
    ],

    'toast' => [
        'reconciled_locked' => 'Ta transakcja jest uzgodniona. Cofnij uzgodnienie, aby wprowadzić zmiany.',
        'reclassified_pair_removed' => 'Nowa klasyfikacja: :type — parowanie usunięte',
        'reclassified' => 'Nowa klasyfikacja: :type',
        'note_saved' => 'Notatka zapisana',
        'unreconciled' => 'Uzgodnienie cofnięte — możesz znowu edytować tę transakcję.',
        'note_too_long' => 'Notatka ma najwyżej :max znak.|Notatka ma najwyżej :max znaki.|Notatka ma najwyżej :max znaków.',
        'counterparty_updated' => 'Kontrahent zaktualizowany',
        'goal_attributed' => 'Zaliczono do tego celu',
        'goal_attribution_removed' => 'Nie jest już zaliczana do tego celu',
        'split_saved' => 'Podział zapisany',
        'removed_one_remains' => 'Usunięto — została jedna kategoria',
        'unsplit_restored' => 'Podział cofnięty — przywrócono jedną kategorię',
    ],

    'errors' => [
        'totals_must_match' => 'Nie udało się zapisać — sumy pozycji muszą dokładnie odpowiadać sumie transakcji.',
        'not_found' => 'Nie znaleziono transakcji.',
        'amount_zero' => 'Kwota nie może wynosić :amount',
        'choose_category' => 'Wybierz kategorię.',
        'choose_before_removing' => 'Wybierz kategorię przed usunięciem.',
        'choose_before_unsplitting' => 'Wybierz kategorię przed cofnięciem podziału.',
        'not_found_or_unowned' => 'Nie znaleziono transakcji lub nie należy ona do użytkownika.',
        'reconciled_split' => 'Ta transakcja jest uzgodniona. Cofnij uzgodnienie, aby zmienić jej podział.',
        'not_splittable' => 'Typ transakcji „:type” nie podlega podziałowi.',
        'min_two_legs' => 'Podział wymaga co najmniej 2 pozycji.',
        'legs_non_zero' => 'Kwoty pozycji nie mogą być zerowe.',
        'legs_parent_sign' => 'Kwoty pozycji muszą mieć ten sam znak co transakcja nadrzędna.',
        'leg_category_not_accessible' => 'Nie znaleziono kategorii pozycji lub użytkownik nie ma do niej dostępu.',
        'survivor_not_accessible' => 'Nie znaleziono pozostającej kategorii lub użytkownik nie ma do niej dostępu.',
        'survivor_must_be_current' => 'Pozostająca kategoria musi być jedną z obecnych kategorii podziału.',
    ],
];
