<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Che cosa farà la conferma con ogni riga. “:new” viene aggiunta al tuo registro; “:duplicate” c’è già da un’importazione precedente e viene saltata, quindi reimportare un estratto che si sovrappone a uno che hai già non costa nulla; “:enriched” corrisponde a una riga che hai già e completa un dettaglio che il primo file non portava, senza aggiungerne una seconda. Finora nulla in questa schermata ha toccato il tuo registro: “:confirm” è il momento in cui succede.',
];
