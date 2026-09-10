<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Tillatelsen banken din ga Beatrax til å lese denne kontoen. Banker er pålagt å la den utløpe: dette samtykket varer i 180 dager, statusen slår om til ”:expiring” to uker før, og mens det er utløpt hentes ingenting — ”:reconnect” logger deg inn hos banken igjen og starter 180 nye dager. Banken din kan også avslutte det tidligere fra sin egen app, og det som allerede er importert går ikke tapt uansett.',
];
