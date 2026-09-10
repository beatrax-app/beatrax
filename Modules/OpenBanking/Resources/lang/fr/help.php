<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'L’autorisation que votre banque a donnée à Beatrax de lire ce compte. Les banques sont tenues de la faire expirer : ce consentement dure 180 jours, le statut passe à « :expiring » quinze jours avant, et tant qu’il a expiré rien n’est récupéré — « :reconnect » vous fait signer à nouveau chez votre banque et repart pour 180 jours. Votre banque peut aussi y mettre fin plus tôt depuis sa propre application, et ce qui est déjà importé n’est perdu dans aucun des cas.',
];
