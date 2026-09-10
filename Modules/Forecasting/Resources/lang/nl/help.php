<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Tegen welke rekening een verwachte betaling wordt geteld. Sommige betalingen verlaten nooit de rekening waar ze vandaan lijken te komen: een kaartaankoop wordt later met één afschrijving van je bankrekening verrekend, en een walletbetaling wordt door een overboeking gevoed. Zet je dit aan, dan telt elk van die betalingen bij de rekening die er werkelijk voor opdraait, zodat de lijn van een rekening het geld toont dat er echt doorheen gaat en niet het geld dat er alleen langskwam.',
];
