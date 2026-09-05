<?php

declare(strict_types=1);

return [
    'aria' => 'Чисті активи',
    'heading' => 'Чисті активи',

    'rate_details' => 'Деталі курсу',
    'rate_details_for' => 'Деталі курсу — :name',

    'across' => 'по :count рахунку|по :count рахунках|по :count рахунках',

    'not_converted' => '· :count рахунок не конвертовано — курс недоступний|· :count рахунки не конвертовано — курс недоступний|· :count рахунків не конвертовано — курс недоступний',
    'no_rate_available' => '· курс недоступний',

    'toggle_hide' => 'Сховати',
    'toggle_breakdown' => 'Деталізація',
    'card_suffix' => '(картка)',

    'converted_to' => 'Конвертовано в :currency',
    'as_of' => 'станом на :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'курси станом на :date, джерело: :source',

    'stale_bundled' => 'Використовується вбудований знімок курсу, якому понад :count день. Увімкни онлайн-оновлення в Налаштуваннях, щоб мати актуальні курси.|Використовується вбудований знімок курсу, якому понад :count дні. Увімкни онлайн-оновлення в Налаштуваннях, щоб мати актуальні курси.|Використовується вбудований знімок курсу, якому понад :count днів. Увімкни онлайн-оновлення в Налаштуваннях, щоб мати актуальні курси.',
    'stale_old' => 'Цьому курсу понад :count день. Наступне онлайн-оновлення його оновить.|Цьому курсу понад :count дні. Наступне онлайн-оновлення його оновить.|Цьому курсу понад :count днів. Наступне онлайн-оновлення його оновить.',
    'stale_offline' => 'Цьому курсу понад :count день, а онлайн-оновлення вимкнено. Увімкни його в Налаштуваннях, щоб курс оновився.|Цьому курсу понад :count дні, а онлайн-оновлення вимкнено. Увімкни його в Налаштуваннях, щоб курс оновився.|Цьому курсу понад :count днів, а онлайн-оновлення вимкнено. Увімкни його в Налаштуваннях, щоб курс оновився.',

    // i18n-review: uk · source_ecb — the value is what this locale's own
    // settings.exchange_rates.online_on already writes, so the card and Settings
    // cannot name the same institution two ways. This language usually
    // abbreviates it ЄЦБ, and moving to that means moving both lines.
    'source_ecb' => 'ECB',
    'source_bundled' => 'Вбудований знімок',
    'source_transaction' => 'Записаний курс',
    'source_fallback' => 'курси',
];
