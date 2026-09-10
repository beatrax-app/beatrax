<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'A quale conto viene imputato un pagamento previsto. Alcuni pagamenti non escono mai dal conto da cui sembrano uscire: un acquisto con carta viene regolato più tardi da un unico addebito sul tuo conto bancario, e un pagamento da portafoglio è alimentato da un bonifico. Con questo attivo ognuno di essi viene imputato al conto che lo paga davvero, così la linea di un conto mostra il denaro che ci passerà per davvero e non quello che è soltanto transitato.',
];
