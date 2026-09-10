<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Hvilken konto en forventet betaling tælles imod. Nogle betalinger forlader aldrig den konto, de ser ud til at forlade: et kortkøb afregnes senere med én postering på din bankkonto, og en tegnebogsbetaling dækkes af en overførsel. Slår du dette til, tælles hver af dem mod den konto, der reelt betaler den, så en kontos linje viser de penge, der virkelig kommer igennem den, og ikke dem, der bare kom forbi.',
];
