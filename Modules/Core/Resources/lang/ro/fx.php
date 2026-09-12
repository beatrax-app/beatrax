<?php

declare(strict_types=1);

return [
    'rate_details' => 'Detalii curs',
    'rate_details_for' => 'Detalii curs pentru :name',

    'converted_to' => 'Convertit în :currency',
    'as_of' => 'la data de :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'cursuri la data de :date, sursă: :source',
    'rates_not_recorded' => 'cursuri neînregistrate — acest rezultat a fost salvat fără ele',

    'stale_bundled' => 'Se folosește un curs dintr-un instantaneu inclus în aplicație, mai vechi de :count zi. Activează reîmprospătarea online în Setări pentru cursuri actuale.|Se folosește un curs dintr-un instantaneu inclus în aplicație, mai vechi de :count zile. Activează reîmprospătarea online în Setări pentru cursuri actuale.|Se folosește un curs dintr-un instantaneu inclus în aplicație, mai vechi de :count de zile. Activează reîmprospătarea online în Setări pentru cursuri actuale.',
    'stale_old' => 'Acest curs este mai vechi de :count zi. Următoarea reîmprospătare online îl va actualiza.|Acest curs este mai vechi de :count zile. Următoarea reîmprospătare online îl va actualiza.|Acest curs este mai vechi de :count de zile. Următoarea reîmprospătare online îl va actualiza.',
    'stale_offline' => 'Acest curs este mai vechi de :count zi, iar reîmprospătarea online este dezactivată. Activeaz-o în Setări pentru a-l actualiza.|Acest curs este mai vechi de :count zile, iar reîmprospătarea online este dezactivată. Activeaz-o în Setări pentru a-l actualiza.|Acest curs este mai vechi de :count de zile, iar reîmprospătarea online este dezactivată. Activeaz-o în Setări pentru a-l actualiza.',

    // i18n-review: ro · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it BCE, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Instantaneu inclus',
    'source_transaction' => 'Curs înregistrat',
    'source_fallback' => 'cursuri',
];
