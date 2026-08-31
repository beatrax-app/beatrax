<?php

declare(strict_types=1);

return [
    'about_body' => 'Přibalený soubor YAML, který přiřazuje kryptickým kódům z bankovních výpisů srozumitelná jména obchodníků. Po zapnutí může Beatrax seznam číst při importu; odeslání návrhu otevře GitHub v prohlížeči.',

    'mappings' => ':count přiřazení|:count přiřazení|:count přiřazení',
    'contributors' => ':count přispěvatel|:count přispěvatelé|:count přispěvatelů',

    'use_shared_list' => [
        'title' => 'Používat sdílený seznam obchodníků',
        'help' => 'Beatrax bude číst přibalený seznam a doplní srozumitelná jména u obchodníků, kteří vlastní název nemají.',
    ],

    'offer_to_contribute' => [
        'title' => 'Nabízet přispění',
        'help' => 'Zobrazovat v řádku třídění výzvu „Pomoz to rozpoznat ostatním“, aby šlo návrh do sdíleného seznamu odeslat jedním kliknutím.',
        // i18n-review: cs · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Zobrazovat v řádku třídění výzvu „Pomoz to rozpoznat ostatním“, aby šlo návrh do sdíleného seznamu odeslat jedním klepnutím.',
    ],

    'update_on_updates' => [
        'title' => 'Aktualizovat sdílený seznam s aktualizacemi aplikace',
        'help' => 'Obnovovat přibalený seznam při každé aktualizaci aplikace Beatrax.',
        'help_phone' => 'Obnovovat přibalený seznam pokaždé, když se z App Store nebo Google Play nainstaluje nová verze Beatraxu.',
        'note' => 'Začne fungovat s budoucí aktualizací aplikace — aktuální verzi najdeš v Nastavení → O aplikaci.',
    ],
];
