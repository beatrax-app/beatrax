<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'En sparepott legger penger til side inne i en konto i stedet for å flytte dem ut, så saldoen hos banken endrer seg ikke når du setter inn eller tar ut. Hver konto viser tre tall: ”:real” er det banken har, ”:allocated” er det sparepottene dine til sammen har gjort krav på, og ”:unallocated” er det som blir igjen — den eneste delen som fortsatt er fri til å brukes. Når det siste tallet havner under null, gjør pottene krav på mer enn kontoen inneholder, og hver pottsaldo er satt for høyt til du tar noe tilbake.',
];
