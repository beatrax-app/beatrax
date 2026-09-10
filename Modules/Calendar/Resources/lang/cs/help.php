<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Z jakých účtů se skládá odhadovaný denní zůstatek. Sousední sloupec „:entries“ rozhoduje o něčem jiném — jestli se platby toho účtu vůbec kreslí do mřížky — takže účet může být vidět, aniž by se počítal, nebo se počítat, aniž by byl vidět. Kde se ty dva rozejdou, řekne to den pod svým zůstatkem, místo aby se číslo potichu poskládalo z méně, než čekáš.',
];
