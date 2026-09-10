<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Leidimas, kurį tavo bankas davė Beatrax skaityti šią sąskaitą. Bankai privalo leisti jam baigtis: šis sutikimas galioja 180 dienų, likus dviem savaitėms būsena pasikeičia į „:expiring“, o kol jis pasibaigęs, nieko nesiunčiama — „:reconnect“ vėl prijungia tave prie banko ir pradeda naujas 180 dienų. Bankas gali jį nutraukti ir anksčiau iš savo programėlės, o tai, kas jau importuota, neprarandama nė vienu atveju.',
];
