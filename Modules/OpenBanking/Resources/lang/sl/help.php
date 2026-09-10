<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Dovoljenje, ki ga je tvoja banka dala Beatraxu za branje tega računa. Banke ga morajo pustiti, da poteče: to soglasje velja 180 dni, dva tedna prej se stanje spremeni v „:expiring“, in dokler je poteklo, se ne prenese nič — „:reconnect“ te znova prijavi pri banki in začne novih 180 dni. Banka ga lahko tudi prej konča iz svoje aplikacije, tisto, kar je že uvoženo, pa se v nobenem primeru ne izgubi.',
];
