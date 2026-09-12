<?php

declare(strict_types=1);

return [
    'rate_details' => 'Деталі курсу',
    'rate_details_for' => 'Деталі курсу — :name',

    'converted_to' => 'Конвертовано в :currency',
    'as_of' => 'станом на :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'курси станом на :date, джерело: :source',
    'rates_not_recorded' => 'курси не записані — цей результат збережено без них',

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
