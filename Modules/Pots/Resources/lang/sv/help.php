<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'En sparpott lägger undan pengar inuti ett konto i stället för att flytta ut dem, så saldot hos banken ändras inte när du fyller på eller tar ut. Varje konto visar tre siffror: ”:real” är vad banken har, ”:allocated” är vad dina sparpotter tillsammans har gjort anspråk på, och ”:unallocated” är det som blir kvar — den enda del som fortfarande är fri att spendera. När den sista siffran hamnar under noll gör potterna anspråk på mer än kontot innehåller, och varje pottsaldo är för högt tills du tar tillbaka något.',
];
