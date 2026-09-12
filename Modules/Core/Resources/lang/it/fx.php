<?php

declare(strict_types=1);

return [
    'rate_details' => 'Dettagli del tasso',
    'rate_details_for' => 'Dettagli del tasso per :name',

    'converted_to' => 'Convertito in :currency',
    'as_of' => 'al :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'tassi al :date da :source',
    'rates_not_recorded' => 'tassi non registrati — questo risultato è stato salvato senza di essi',

    'stale_bundled' => "Viene usato un tasso dello snapshot incluso nell'app, con più di :count giorno. Attiva l'aggiornamento online nelle Impostazioni per avere i tassi correnti.|Viene usato un tasso dello snapshot incluso nell'app, con più di :count giorni. Attiva l'aggiornamento online nelle Impostazioni per avere i tassi correnti.",
    'stale_old' => 'Questo tasso ha più di :count giorno. Il prossimo aggiornamento online lo rinnoverà.|Questo tasso ha più di :count giorni. Il prossimo aggiornamento online lo rinnoverà.',
    'stale_offline' => "Questo tasso ha più di :count giorno e l'aggiornamento online è disattivato. Attivalo nelle Impostazioni per rinnovarlo.|Questo tasso ha più di :count giorni e l'aggiornamento online è disattivato. Attivalo nelle Impostazioni per rinnovarlo.",

    'source_ecb' => 'BCE',
    'source_bundled' => 'Snapshot incluso',
    'source_transaction' => 'Tasso registrato',
    'source_fallback' => 'tassi',
];
