<?php

declare(strict_types=1);

return [
    'about_body' => 'Dołączony plik YAML, który przypisuje zagadkowym kodom z wyciągów bankowych czytelne nazwy sprzedawców. Włączenie pozwala aplikacji Beatrax czytać tę listę podczas importu; wysłanie propozycji otwiera GitHub w przeglądarce.',

    'mappings' => ':count przypisanie|:count przypisania|:count przypisań',
    'contributors' => ':count współtwórca|:count współtwórcy|:count współtwórców',

    'use_shared_list' => [
        'title' => 'Używaj wspólnej listy sprzedawców',
        'help' => 'Pozwól aplikacji Beatrax czytać dołączoną listę, aby uzupełniać czytelne nazwy sprzedawców, których nazw nie zmieniono ręcznie.',
    ],

    'offer_to_contribute' => [
        'title' => 'Proponuj współtworzenie',
        'help' => 'Pokazuj przycisk „Pomóż innym to rozpoznać” w wierszu porządkowania, aby jednym kliknięciem wysłać propozycję do wspólnej listy.',
        // i18n-review: pl · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Pokazuj przycisk „Pomóż innym to rozpoznać” w wierszu porządkowania, aby jednym dotknięciem wysłać propozycję do wspólnej listy.',
    ],

    'update_on_updates' => [
        'title' => 'Aktualizuj wspólną listę przy aktualizacjach aplikacji',
        'help' => 'Odświeżaj dołączoną listę przy każdej aktualizacji aplikacji Beatrax.',
        'help_phone' => 'Odświeżaj dołączoną listę za każdym razem, gdy z App Store lub Google Play zostanie zainstalowana nowa wersja Beatraxa.',
        'note' => 'Zadziała po przyszłej aktualizacji aplikacji — obecną wersję znajdziesz w Ustawienia → O aplikacji.',
    ],
];
