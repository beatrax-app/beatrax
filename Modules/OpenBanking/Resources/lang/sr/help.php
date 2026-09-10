<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Dozvola koju je tvoja banka dala Beatraxu da čita ovaj račun. Banke moraju da je puste da istekne: ova saglasnost traje 180 dana, dve nedelje pre toga status prelazi u „:expiring”, a dok je istekla ne preuzima se ništa — „:reconnect” te ponovo prijavljuje u banku i pokreće novih 180 dana. Banka može da je prekine i ranije iz svoje aplikacije, a ono što je već uvezeno ni u kom se slučaju ne gubi.',
];
