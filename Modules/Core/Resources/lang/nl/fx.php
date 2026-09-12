<?php

declare(strict_types=1);

return [
    'rate_details' => 'Koersdetails',
    'rate_details_for' => 'Koersdetails voor :name',

    'converted_to' => 'Omgerekend naar :currency',
    'as_of' => 'per :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'koersen per :date van :source',

    'stale_bundled' => 'Er wordt een meegeleverde koers gebruikt van meer dan :count dag oud. Schakel online vernieuwen in bij Instellingen voor actuele koersen.|Er wordt een meegeleverde koers gebruikt van meer dan :count dagen oud. Schakel online vernieuwen in bij Instellingen voor actuele koersen.',
    'stale_old' => 'Deze koers is meer dan :count dag oud. De volgende online vernieuwing werkt hem bij.|Deze koers is meer dan :count dagen oud. De volgende online vernieuwing werkt hem bij.',
    'stale_offline' => 'Deze koers is meer dan :count dag oud en online vernieuwen staat uit. Schakel het in bij Instellingen om hem bij te werken.|Deze koers is meer dan :count dagen oud en online vernieuwen staat uit. Schakel het in bij Instellingen om hem bij te werken.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Meegeleverde momentopname',
    'source_transaction' => 'Vastgelegde koers',
    'source_fallback' => 'koersen',
];
