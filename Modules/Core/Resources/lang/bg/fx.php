<?php

declare(strict_types=1);

return [
    'rate_details' => 'Детайли за курса',
    'rate_details_for' => 'Детайли за курса на :name',

    'converted_to' => 'Конвертирано в :currency',
    'as_of' => 'към :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'курсове към :date от :source',
    'rates_not_recorded' => 'курсовете не са записани — този резултат е запазен без тях',

    'stale_bundled' => 'Използва се курс от вградена моментна снимка на повече от :count ден. Включи онлайн обновяването в Настройки за актуални курсове.|Използва се курс от вградена моментна снимка на повече от :count дни. Включи онлайн обновяването в Настройки за актуални курсове.',
    'stale_old' => 'Този курс е на повече от :count ден. Следващото онлайн обновяване ще го актуализира.|Този курс е на повече от :count дни. Следващото онлайн обновяване ще го актуализира.',
    'stale_offline' => 'Този курс е на повече от :count ден, а онлайн обновяването е изключено. Включи го в Настройки, за да се актуализира.|Този курс е на повече от :count дни, а онлайн обновяването е изключено. Включи го в Настройки, за да се актуализира.',

    // i18n-review: bg · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it ЕЦБ, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Вградена моментна снимка',
    'source_transaction' => 'Записан курс',
    'source_fallback' => 'курсове',
];
