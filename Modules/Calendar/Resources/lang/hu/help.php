<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Mely számlákból épül a becsült napi egyenleg. A melletti oszlop, a „:entries”, másról dönt — arról, hogy az adott számla fizetései egyáltalán megjelennek-e a rácson — így egy számla látszhat úgy, hogy nem számít bele, és beleszámíthat úgy, hogy nem látszik. Ahol a kettő eltér, a nap ezt kiírja az egyenlege alatt, ahelyett hogy egy szám csendben kevesebből állna össze, mint várnád.',
];
