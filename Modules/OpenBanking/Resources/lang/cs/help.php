<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Povolení, které tvá banka dala Beatraxu ke čtení tohoto účtu. Banky musí nechat souhlas vypršet: tento platí 180 dní, dva týdny předtím se stav změní na „:expiring“ a dokud je prošlý, nestahuje se nic — „:reconnect“ tě znovu přihlásí u banky a rozjede dalších 180 dní. Banka ho může ukončit i dřív ze své vlastní aplikace a to, co už je naimportované, se v žádném případě neztratí.',
];
