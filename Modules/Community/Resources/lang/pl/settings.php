<?php

declare(strict_types=1);

return [
    'about_heading' => 'O wspólnej liście',
    'about_body' => 'Dołączony plik YAML, który przypisuje zagadkowym kodom z wyciągów bankowych czytelne nazwy sprzedawców. Włączenie pozwala aplikacji Beatrax czytać tę listę podczas importu; wysłanie propozycji otwiera GitHub w przeglądarce.',

    'mappings' => 'Przypisania',
    'contributors' => 'Współtwórcy',

    'use_shared_list' => [
        'title' => 'Używaj wspólnej listy sprzedawców',
        'help' => 'Pozwól aplikacji Beatrax czytać dołączoną listę, aby uzupełniać czytelne nazwy sprzedawców, których nazw nie zmieniono ręcznie.',
    ],

    'offer_to_contribute' => [
        'title' => 'Proponuj współtworzenie',
        'help' => 'Pokazuj przycisk „Pomóż innym to rozpoznać” w wierszu porządkowania, aby jednym kliknięciem wysłać propozycję do wspólnej listy.',
    ],

    'update_on_updates' => [
        'title' => 'Aktualizuj wspólną listę przy aktualizacjach aplikacji',
        'help' => 'Odświeżaj dołączoną listę przy każdej aktualizacji aplikacji Beatrax.',
        'note' => 'Zadziała po przyszłej aktualizacji aplikacji — obecną wersję znajdziesz w Ustawienia → O aplikacji.',
    ],
];
