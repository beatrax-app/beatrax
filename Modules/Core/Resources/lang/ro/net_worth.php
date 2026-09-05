<?php

declare(strict_types=1);

return [
    'aria' => 'Patrimoniu net',
    'heading' => 'Patrimoniu net',

    'rate_details' => 'Detalii curs',
    'rate_details_for' => 'Detalii curs pentru :name',

    'across' => 'în :count cont|în :count conturi|în :count de conturi',

    'not_converted' => '· :count cont neconvertit — niciun curs disponibil|· :count conturi neconvertite — niciun curs disponibil|· :count de conturi neconvertite — niciun curs disponibil',
    'no_rate_available' => '· niciun curs disponibil',

    'toggle_hide' => 'Ascunde',
    'toggle_breakdown' => 'Detaliere',
    'card_suffix' => '(card)',

    'converted_to' => 'Convertit în :currency',
    'as_of' => 'la data de :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'cursuri la data de :date, sursă: :source',

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
