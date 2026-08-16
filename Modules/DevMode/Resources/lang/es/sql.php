<?php

declare(strict_types=1);

return [
    'tables' => 'Tablas',
    'schema_viewer_aria' => 'Visor de esquema',
    'columns' => 'columnas',
    'indexes' => 'índices',
    'foreign_keys' => 'claves foráneas',
    'browse' => 'Explorar',
    'heading' => 'SQL',

    'subtitle_html' => 'Panel de consultas solo con SELECT. El validador (en el análisis) y PRAGMA <code class="font-mono text-xs">query_only = 1</code> (en el motor) rechazan todo lo que no sea SELECT. Límite estricto de 5 segundos de reloj.',
    'advanced_off_strong' => 'El modo avanzado está DESACTIVADO.',
    'advanced_off_hint' => 'Activa el modo avanzado (Dev Mode → Avanzado) para ejecutar consultas.',
    'statement_label' => 'Sentencia SELECT',
    'run' => 'Ejecutar',
    'rows_meta' => ':rows filas · :durationms',
    'no_rows' => 'La consulta no ha devuelto ninguna fila.',

    'errors' => [
        'advanced_off' => 'Activa el modo avanzado (Dev Mode → Avanzado) para ejecutar consultas.',
        'only_select' => 'Solo se permiten sentencias SELECT. Motivo del rechazo: :reason.',
        'timeout' => 'La consulta ha superado el tiempo límite de 5 segundos. Ajústala e inténtalo de nuevo.',
        'engine' => 'Error de SQL: :message',
        'unknown_table' => 'Tabla desconocida.',
    ],
];
