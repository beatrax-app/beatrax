<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'El permiso que tu banco dio a Beatrax para leer esta cuenta. Los bancos están obligados a hacerlo caducar: este consentimiento dura 180 días, el estado pasa a “:expiring” quince días antes, y mientras está caducado no se descarga nada — “:reconnect” te hace iniciar sesión otra vez en tu banco y arranca 180 días nuevos. Tu banco también puede terminarlo antes desde su propia app, y lo que ya está importado no se pierde en ningún caso.',
];
