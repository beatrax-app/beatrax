<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Vilka konton det prognostiserade dagssaldot byggs av. Kolumnen bredvid, ”:entries”, avgör något annat — om det kontots betalningar över huvud taget ritas ut i rutnätet — så ett konto kan visas utan att räknas, eller räknas utan att visas. Där de två går isär säger dagen det under sitt saldo, i stället för att låta en siffra tyst byggas av mindre än du väntar dig.',
];
