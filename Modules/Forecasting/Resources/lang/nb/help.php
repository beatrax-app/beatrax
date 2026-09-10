<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Hvilken konto en anslått betaling regnes mot. Noen betalinger forlater aldri kontoen de ser ut til å forlate: et kortkjøp gjøres opp senere med én belastning på bankkontoen din, og en lommebokbetaling dekkes av en overføring. Slår du dette på, regnes hver av dem mot kontoen som faktisk betaler den, slik at linjen til en konto viser pengene som virkelig går gjennom den og ikke de som bare passerte.',
];
