<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Az az engedély, amelyet a bankod adott a Beatraxnak, hogy olvashassa ezt a számlát. A bankoknak le kell járatniuk: ez a hozzájárulás 180 napig érvényes, két héttel előtte az állapot „:expiring” lesz, és amíg lejárt, semmi nem töltődik le — a „:reconnect” újra beléptet a bankodnál, és új 180 nap indul. A bankod a saját alkalmazásából korábban is lezárhatja, és a már beimportált adat egyik esetben sem vész el.',
];
