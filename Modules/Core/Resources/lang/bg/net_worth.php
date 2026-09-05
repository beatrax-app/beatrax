<?php

declare(strict_types=1);

return [
    'aria' => 'Нетна стойност',
    'heading' => 'Нетна стойност',

    'rate_details' => 'Детайли за курса',
    'rate_details_for' => 'Детайли за курса на :name',

    'across' => 'в :count сметка|в :count сметки',

    'not_converted' => '· :count сметка не е конвертирана — няма наличен курс|· :count сметки не са конвертирани — няма наличен курс',
    'no_rate_available' => '· няма наличен курс',

    'toggle_hide' => 'Скрий',
    'toggle_breakdown' => 'Разбивка',
    'card_suffix' => '(карта)',

    'converted_to' => 'Конвертирано в :currency',
    'as_of' => 'към :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'курсове към :date от :source',

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
