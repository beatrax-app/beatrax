<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Tillståndet din bank gav Beatrax att läsa det här kontot. Banker är skyldiga att låta det löpa ut: det här samtycket varar 180 dagar, statusen slår om till ”:expiring” två veckor innan, och medan det har löpt ut hämtas ingenting — ”:reconnect” loggar in dig hos banken igen och startar 180 nya dagar. Din bank kan också avsluta det i förtid från sin egen app, och det som redan importerats går inte förlorat i något av fallen.',
];
