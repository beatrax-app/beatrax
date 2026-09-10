<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Da quali conti è costruito il saldo giornaliero proiettato. La colonna accanto, “:entries”, decide un’altra cosa — se i pagamenti di quel conto vengano disegnati sulla griglia — perciò un conto può essere mostrato senza contare, o contare senza essere mostrato. Dove le due non coincidono il giorno lo dice sotto il proprio saldo, invece di lasciare che una cifra si formi in silenzio con meno di quanto ti aspetti.',
];
