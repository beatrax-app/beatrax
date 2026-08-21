<?php

declare(strict_types=1);

return [
    'tables' => 'Tabelas',
    'schema_viewer_aria' => 'Visualizador do esquema',
    'columns' => 'colunas',
    'indexes' => 'índices',
    'foreign_keys' => 'chaves estrangeiras',
    'browse' => 'Explorar',
    'heading' => 'SQL',

    'subtitle_html' => 'Painel de consultas apenas SELECT. O validador (em tempo de análise) e o PRAGMA <code class="font-mono text-xs">query_only = 1</code> (em tempo de motor) rejeitam tudo o que não seja SELECT. Limite rígido de 5 segundos de tempo real.',
    'advanced_off_strong' => 'O modo avançado está DESLIGADO.',
    'advanced_off_hint' => 'Ativa o modo avançado (Modo Dev → Avançado) para executares consultas.',
    'statement_label' => 'Instrução SELECT',
    'run' => 'Executar',
    'rows_meta' => ':rows linha · :durationms|:rows linhas · :durationms',
    'no_rows' => 'A consulta não devolveu linhas.',

    'errors' => [
        'advanced_off' => 'Ativa o modo avançado (Modo Dev → Avançado) para executares consultas.',
        'only_select' => 'Só são permitidas instruções SELECT. Motivo da rejeição: :reason.',
        'timeout' => 'A consulta excedeu o limite de 5 segundos. Afina a consulta e tenta de novo.',
        'engine' => 'Erro de SQL: :message',
        'unknown_table' => 'Tabela desconhecida.',
    ],
];
