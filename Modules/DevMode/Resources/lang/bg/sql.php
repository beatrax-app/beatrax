<?php

declare(strict_types=1);

return [
    'tables' => 'Таблици',
    'schema_viewer_aria' => 'Преглед на схемата',
    'columns' => 'колони',
    'indexes' => 'индекси',
    'foreign_keys' => 'външни ключове',
    'browse' => 'Разгледай',
    'heading' => 'SQL',

    'subtitle_html' => 'Панел за заявки само от тип SELECT. Валидаторът (при анализа) и PRAGMA <code class="font-mono text-xs">query_only = 1</code> (в двигателя) отхвърлят всичко, което не е SELECT. Твърд лимит от 5 секунди.',
    'advanced_off_strong' => 'Режимът Advanced е ИЗКЛЮЧЕН.',
    'advanced_off_hint' => 'Включи Advanced (Dev Mode → Advanced), за да изпълняваш заявки.',
    'statement_label' => 'SELECT заявка',
    'run' => 'Изпълни',
    'rows_meta' => ':rows ред · :durationms|:rows реда · :durationms',
    'no_rows' => 'Заявката не върна редове.',

    'errors' => [
        'advanced_off' => 'Включи Advanced (Dev Mode → Advanced), за да изпълняваш заявки.',
        'only_select' => 'Разрешени са само SELECT заявки. Причина за отказа: :reason.',
        'timeout' => 'Заявката надхвърли лимита от 5 секунди. Прецизирай заявката и опитай отново.',
        'engine' => 'SQL грешка: :message',
        'unknown_table' => 'Неизвестна таблица.',
    ],
];
