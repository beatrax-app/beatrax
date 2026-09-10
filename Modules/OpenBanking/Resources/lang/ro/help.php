<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Permisiunea pe care banca ta i-a dat-o Beatrax să citească acest cont. Băncile sunt obligate să o lase să expire: acest consimțământ ține 180 de zile, cu două săptămâni înainte starea trece în „:expiring”, iar cât timp a expirat nu se descarcă nimic — „:reconnect” te conectează din nou la bancă și pornește alte 180 de zile. Banca îl poate încheia și mai devreme din propria aplicație, iar ce a fost deja importat nu se pierde în niciun caz.',
];
