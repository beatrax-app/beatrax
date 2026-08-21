<?php

declare(strict_types=1);

return [
    'tables' => 'Tables',
    'schema_viewer_aria' => 'Schema viewer',
    'columns' => 'columns',
    'indexes' => 'indexes',
    'foreign_keys' => 'foreign keys',
    'browse' => 'Browse',
    'heading' => 'SQL',

    'subtitle_html' => 'SELECT-only query panel. The validator (parse-time) and PRAGMA <code class="font-mono text-xs">query_only = 1</code> (engine-time) reject every non-SELECT. Hard 5-second wall-clock cap.',
    'advanced_off_strong' => 'Advanced mode is OFF.',
    'advanced_off_hint' => 'Enable Advanced (Dev Mode → Advanced) to run queries.',
    'statement_label' => 'SELECT statement',
    'run' => 'Run',
    'rows_meta' => ':rows row · :durationms|:rows rows · :durationms',
    'no_rows' => 'Query returned no rows.',

    'errors' => [
        'advanced_off' => 'Enable Advanced (Dev Mode → Advanced) to run queries.',
        'only_select' => 'Only SELECT statements are allowed. Reject reason: :reason.',
        'timeout' => 'Query exceeded the 5-second timeout. Refine your query and try again.',
        'engine' => 'SQL error: :message',
        'unknown_table' => 'Unknown table.',
    ],
];
