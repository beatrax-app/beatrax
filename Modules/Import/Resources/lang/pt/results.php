<?php

declare(strict_types=1);

return [
    'page_title' => 'Importação concluída',
    'heading' => 'Importação concluída',

    'summary' => ':count transação importada|:count transações importadas',
    'summary_duplicates' => ' · :count duplicado ignorado| · :count duplicados ignorados',
    'summary_enriched' => ' · :count enriquecidas',
    'summary_errors' => ' · :count erro| · :count erros',

    'show_duplicates' => 'Mostrar os duplicados ignorados (:count)',
    'duplicates_help' => 'Os duplicados são linhas já presentes no teu livro-razão — são ignoradas em silêncio ao reimportar.',
    'show_errors' => 'Mostrar os erros (:count)',
    'errors_help' => 'Os erros são linhas que não foi possível interpretar; não foram adicionadas ao teu livro-razão.',

    'upload_another' => 'Carregar outro extrato',

    'chain' => [
        'heading' => 'Resolução de cadeias',
        'pending' => 'A resolução de cadeias não chegou a começar, por isso as cadeias de financiamento não foram ligadas.',
        'running' => 'A ligar cadeias de financiamento e a decompor liquidações de extrato.',
    ],

    'issues' => [
        'row' => 'Linha :row: :reason',
        'file_stopped' => 'O ficheiro não pôde ser lido para além da linha :row. Nada depois dessa linha foi importado.',
        'file_none' => 'O ficheiro não pôde ser lido de todo.',
        'detail' => 'O leitor reportou: :reason',
        'duplicate' => 'A linha :row já estava no teu livro-razão.',
        'more' => '+ :count não listadas',
    ],
];
