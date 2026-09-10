<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Povolenie, ktoré tvoja banka dala Beatraxu na čítanie tohto účtu. Banky musia nechať súhlas vypršať: tento platí 180 dní, dva týždne predtým sa stav zmení na „:expiring“ a kým je po platnosti, nesťahuje sa nič — „:reconnect“ ťa znova prihlási v banke a rozbehne ďalších 180 dní. Banka ho môže ukončiť aj skôr zo svojej aplikácie a to, čo je už naimportované, sa v žiadnom prípade nestratí.',
];
