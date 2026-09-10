<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Il permesso che la tua banca ha dato a Beatrax di leggere questo conto. Le banche sono tenute a farlo scadere: questo consenso dura 180 giorni, lo stato passa a “:expiring” due settimane prima, e finché è scaduto non viene scaricato nulla — “:reconnect” ti fa accedere di nuovo alla tua banca e riparte con 180 giorni. La tua banca può anche chiuderlo prima dalla propria app, e quello che è già stato importato non si perde in nessun caso.',
];
