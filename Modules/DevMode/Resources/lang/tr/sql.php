<?php

declare(strict_types=1);

return [
    'tables' => 'Tablolar',
    'schema_viewer_aria' => 'Şema görüntüleyici',
    'columns' => 'sütun',
    'indexes' => 'dizin',
    'foreign_keys' => 'yabancı anahtar',
    'browse' => 'Gözat',
    'heading' => 'SQL',

    'subtitle_html' => 'Yalnızca SELECT çalıştıran sorgu paneli. Doğrulayıcı (ayrıştırma sırasında) ve PRAGMA <code class="font-mono text-xs">query_only = 1</code> (motor tarafında) SELECT olmayan her ifadeyi reddeder. Kesin 5 saniyelik süre sınırı vardır.',
    'advanced_off_strong' => 'Advanced modu KAPALI.',
    'advanced_off_hint' => 'Sorgu çalıştırmak için Advanced ayarını aç (Dev Mode → Advanced).',
    'statement_label' => 'SELECT ifadesi',
    'run' => 'Çalıştır',
    'rows_meta' => ':rows satır · :durationms',
    'no_rows' => 'Sorgu hiç satır döndürmedi.',

    'errors' => [
        'advanced_off' => 'Sorgu çalıştırmak için Advanced ayarını aç (Dev Mode → Advanced).',
        'only_select' => 'Yalnızca SELECT ifadelerine izin verilir. Ret nedeni: :reason.',
        'timeout' => 'Sorgu 5 saniyelik zaman aşımını aştı. Sorgunu daraltıp yeniden dene.',
        'engine' => 'SQL hatası: :message',
        'unknown_table' => 'Bilinmeyen tablo.',
    ],
];
