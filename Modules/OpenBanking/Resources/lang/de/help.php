<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Die Erlaubnis, die deine Bank Beatrax zum Lesen dieses Kontos gegeben hat. Banken müssen sie ablaufen lassen: diese Zustimmung gilt 180 Tage, zwei Wochen vorher wechselt der Status auf „:expiring“, und solange sie abgelaufen ist, wird nichts abgerufen — „:reconnect“ meldet dich erneut bei deiner Bank an und startet 180 neue Tage. Deine Bank kann sie auch früher aus ihrer eigenen App beenden, und was bereits importiert ist, geht so oder so nicht verloren.',
];
