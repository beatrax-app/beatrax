<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Dopuštenje koje je tvoja banka dala Beatraxu da čita ovaj račun. Banke ga moraju pustiti da istekne: ova privola traje 180 dana, dva tjedna prije toga status prelazi u „:expiring”, a dok je istekla ne dohvaća se ništa — „:reconnect” te ponovno prijavljuje u banku i pokreće novih 180 dana. Banka ga može prekinuti i ranije iz svoje aplikacije, a ono što je već uvezeno ni u kojem se slučaju ne gubi.',
];
