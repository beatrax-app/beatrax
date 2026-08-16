<?php

declare(strict_types=1);

return [
    'tables' => 'Tabelle',
    'schema_viewer_aria' => 'Visualizzatore dello schema',
    'columns' => 'colonne',
    'indexes' => 'indici',
    'foreign_keys' => 'chiavi esterne',
    'browse' => 'Sfoglia',
    'heading' => 'SQL',

    'subtitle_html' => 'Pannello di query solo SELECT. Il validatore (in fase di parsing) e PRAGMA <code class="font-mono text-xs">query_only = 1</code> (a livello di engine) rifiutano ogni statement diverso da SELECT. Limite rigido di 5 secondi di esecuzione.',
    'advanced_off_strong' => 'La modalità Advanced è OFF.',
    'advanced_off_hint' => 'Attiva Advanced (Dev Mode → Advanced) per eseguire le query.',
    'statement_label' => 'Statement SELECT',
    'run' => 'Esegui',
    'rows_meta' => ':rows righe · :durationms',
    'no_rows' => 'La query non ha restituito righe.',

    'errors' => [
        'advanced_off' => 'Attiva Advanced (Dev Mode → Advanced) per eseguire le query.',
        'only_select' => 'Sono consentiti solo statement SELECT. Motivo del rifiuto: :reason.',
        'timeout' => 'La query ha superato il timeout di 5 secondi. Affina la query e riprova.',
        'engine' => 'Errore SQL: :message',
        'unknown_table' => 'Tabella sconosciuta.',
    ],
];
