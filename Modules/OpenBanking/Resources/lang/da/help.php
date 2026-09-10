<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Den tilladelse, din bank gav Beatrax til at læse denne konto. Banker er forpligtet til at lade den udløbe: dette samtykke varer 180 dage, statussen skifter til ”:expiring” fjorten dage før, og mens det er udløbet hentes der ingenting — ”:reconnect” logger dig ind hos banken igen og starter 180 nye dage. Din bank kan også afslutte det før tid fra sin egen app, og det, der allerede er importeret, går ikke tabt i nogen af tilfældene.',
];
