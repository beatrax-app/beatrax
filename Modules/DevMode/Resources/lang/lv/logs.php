<?php

declare(strict_types=1);

return [
    'heading' => 'Žurnāli',
    'subtitle' => 'Šodienas Laravel žurnāla faila tiešraides plūsma ar dubultu aizsardzību — datu maskēšanu gan ierakstot, gan straumējot.',
    'truncate' => 'Iztukšot',
    'truncate_confirm' => 'Iztukšot šodienas žurnāla failu? To nevar atsaukt.',
    'truncate_title' => 'Iztukšot šodienas žurnāla failu (saglabā inode, lai lasītājs turpinātu bez pārtraukuma)',
    'filters_aria' => 'Žurnāla filtri',
    'severity_aria' => 'Nopietnības filtrs',
    'channel_placeholder' => 'Kanāla filtrs…',
    'channel_aria' => 'Kanāla filtrs',
    'contains_placeholder' => 'Meklēt redzamajā…',
    'contains_aria' => 'Satura filtrs',
    'pause' => 'Pauzēt',
    'resume' => 'Turpināt',
    'waiting' => 'Gaida žurnāla rindas…',
    'copy' => 'Kopēt',
    'copy_title' => 'Kopēt pilnu ierakstu',
    'copy_title_copied' => 'Nokopēts',
    'copy_aria' => 'Kopēt žurnāla ierakstu',
    'copy_aria_copied' => 'Nokopēts starpliktuvē',
    'dismiss' => 'Aizvērt',
    'dismiss_title' => 'Noņemt no skata (žurnāla failu nemaina)',
    'dismiss_aria' => 'Noņemt žurnāla ierakstu no skata',
    'totals' => [
        'showing' => 'Rāda :shown no :count saņemtajām rindām (bufera ierobežojums :cap)|Rāda :shown no :count saņemtās rindas (bufera ierobežojums :cap)|Rāda :shown no :count saņemtajām rindām (bufera ierobežojums :cap)',
        'lines_today' => ':count rindu šodien|:count rinda šodien|:count rindas šodien',
        'lines_today_capped' => 'vairāk nekā :count rindu šodien|vairāk nekā :count rinda šodien|vairāk nekā :count rindas šodien',
        'today' => 'šodien',
        // i18n-review: lv · totals.all_files — written with a bare locative and no
        // preposition, because pa governs a case the size phrase before it does not
        // supply. A native reader decides whether :size :count dienas failos reads.
        'all_files' => ':size :count dienas failos|:size :count dienas failā|:size :count dienas failos',
    ],

    'status' => [
        'poll_interrupted' => 'Žurnāla aptaujāšana pārtraukta. Mēģina vēlreiz…',
        'paused' => 'Pauzēts.',
        'copy_failed_prefix' => 'Kopēšana neizdevās: ',
        'clipboard_unavailable' => 'starpliktuve nav pieejama',
    ],

    'toast' => [
        'truncated' => 'Žurnāls iztukšots — atbrīvots :size.',
        'nothing' => 'Nav ko iztukšot.',
    ],
];
