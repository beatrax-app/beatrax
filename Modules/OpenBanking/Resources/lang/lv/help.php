<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Atļauja, ko tava banka deva Beatrax lasīt šo kontu. Bankām tā ir jāļauj beigties: šī piekrišana ir spēkā 180 dienas, divas nedēļas pirms tam statuss kļūst „:expiring“, un, kamēr tā ir beigusies, nekas netiek ielasīts — „:reconnect“ tevi bankā pieteic no jauna un sāk jaunas 180 dienas. Banka to var izbeigt arī agrāk savā lietotnē, un jau importētais nevienā gadījumā netiek zaudēts.',
];
