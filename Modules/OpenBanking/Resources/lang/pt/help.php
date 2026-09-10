<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'A permissão que o teu banco deu ao Beatrax para ler esta conta. Os bancos são obrigados a fazê-la expirar: este consentimento dura 180 dias, o estado passa a “:expiring” quinze dias antes, e enquanto estiver caducado não é obtido nada — “:reconnect” faz-te iniciar sessão outra vez no teu banco e recomeça 180 dias. O teu banco também pode terminá-lo mais cedo a partir da sua própria app, e o que já foi importado não se perde em nenhum dos casos.',
];
