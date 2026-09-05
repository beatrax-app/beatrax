<?php

declare(strict_types=1);

return [
    'aria' => 'Nettó vagyon',
    'heading' => 'Nettó vagyon',

    'rate_details' => 'Árfolyam részletei',
    'rate_details_for' => 'Árfolyam részletei — :name',

    'across' => ':count számlán|:count számlán',

    'not_converted' => '· :count számla nincs átváltva — nincs elérhető árfolyam|· :count számla nincs átváltva — nincs elérhető árfolyam',
    'no_rate_available' => '· nincs elérhető árfolyam',

    'toggle_hide' => 'Elrejtés',
    'toggle_breakdown' => 'Bontás',
    'card_suffix' => '(kártya)',

    'converted_to' => 'Átváltva erre: :currency',
    'as_of' => 'állapot: :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'árfolyamok, állapot: :date, forrás: :source',

    'stale_bundled' => 'A csomagban szállított, :count napnál régebbi pillanatkép árfolyamát használjuk. Az aktuális árfolyamokhoz kapcsold be az online frissítést a Beállításokban.|A csomagban szállított, :count napnál régebbi pillanatkép árfolyamát használjuk. Az aktuális árfolyamokhoz kapcsold be az online frissítést a Beállításokban.',
    'stale_old' => 'Ez az árfolyam :count napnál régebbi. A következő online frissítés aktualizálja.|Ez az árfolyam :count napnál régebbi. A következő online frissítés aktualizálja.',
    'stale_offline' => 'Ez az árfolyam :count napnál régebbi, és az online frissítés ki van kapcsolva. Kapcsold be a Beállításokban, hogy frissüljön.|Ez az árfolyam :count napnál régebbi, és az online frissítés ki van kapcsolva. Kapcsold be a Beállításokban, hogy frissüljön.',

    // i18n-review: hu · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it EKB, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Csomagolt pillanatkép',
    'source_transaction' => 'Rögzített árfolyam',
    'source_fallback' => 'árfolyamok',
];
