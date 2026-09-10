<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'En opsparingspulje lægger penge til side inde i en konto i stedet for at flytte dem ud, så saldoen i banken ændrer sig ikke, når du indsætter eller hæver. Hver konto viser tre tal: ”:real” er det, banken har, ”:allocated” er det, dine puljer tilsammen har gjort krav på, og ”:unallocated” er det, der er tilbage — den eneste del, der stadig er fri at bruge. Når det sidste tal falder under nul, gør puljerne krav på mere, end kontoen indeholder, og hver puljesaldo er sat for højt, indtil du tager noget tilbage.',
];
