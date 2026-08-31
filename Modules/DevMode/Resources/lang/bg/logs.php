<?php

declare(strict_types=1);

return [
    'heading' => 'Дневници',
    'subtitle' => 'Живо проследяване на днешния файл с дневник на Laravel с двойна защита: заличаване при запис и при подаване.',
    'truncate' => 'Изпразни',
    'truncate_confirm' => 'Да се изпразни днешният файл с дневник? Това не може да се отмени.',
    'truncate_title' => 'Изпразва днешния файл с дневник (запазва inode, така че проследяването продължава чисто)',
    'filters_aria' => 'Филтри на дневника',
    'severity_aria' => 'Филтър по тежест',
    'channel_placeholder' => 'Филтър по канал…',
    'channel_aria' => 'Филтър по канал',
    'contains_placeholder' => 'Търси във видимите…',
    'contains_aria' => 'Филтър по съдържание',
    'pause' => 'Пауза',
    'resume' => 'Продължи',
    'waiting' => 'Изчакване на редове от дневника…',
    'copy' => 'Копирай',
    'copy_title' => 'Копирай целия запис',
    'copy_title_copied' => 'Копирано',
    'copy_aria' => 'Копирай записа от дневника',
    'copy_aria_copied' => 'Копирано в клипборда',
    'dismiss' => 'Отхвърли',
    'dismiss_title' => 'Скрий от изгледа (не променя файла с дневника)',
    'dismiss_aria' => 'Отхвърли записа от дневника в изгледа',
    'totals' => [
        'showing' => 'Показване на :shown от :count получен ред (лимит на буфера :cap)|Показване на :shown от :count получени реда (лимит на буфера :cap)',
        'lines_today' => ':count ред днес|:count реда днес',
        'lines_today_capped' => 'над :count ред днес|над :count реда днес',
        'today' => 'днес',
        'all_files' => ':size в :count дневен файл|:size в :count дневни файла',
    ],

    'status' => [
        'poll_interrupted' => 'Заявката към дневника прекъсна. Нов опит…',
        'paused' => 'На пауза.',
        'copy_failed_prefix' => 'Копирането не успя: ',
        'clipboard_unavailable' => 'клипбордът не е достъпен',
    ],

    'toast' => [
        'truncated' => 'Дневникът е изпразнен — освободени са :size.',
        'nothing' => 'Няма какво да се изпразни.',
    ],
];
