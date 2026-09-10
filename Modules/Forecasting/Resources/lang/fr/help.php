<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Le compte auquel un paiement projeté est imputé. Certains paiements ne quittent jamais le compte dont ils semblent partir : un achat par carte est réglé plus tard par un seul débit sur votre compte bancaire, et un paiement par portefeuille est alimenté par un virement. Activez ceci et chacun d’eux est imputé au compte qui le paie réellement, de sorte que la courbe d’un compte montre l’argent qui le traversera vraiment et non celui qui n’a fait que passer.',
];
