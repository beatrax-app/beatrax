<?php

declare(strict_types=1);

return [
    'page_title' => 'Importação concluída',
    'heading' => 'Importação concluída',

    'summary' => ':inserted transações importadas · :duplicates duplicados ignorados',
    'summary_enriched' => ' · :count enriquecidas',
    'summary_errors' => ' · :count erros',

    'show_duplicates' => 'Mostrar os duplicados ignorados (:count)',
    'duplicates_help' => 'Os duplicados são linhas já presentes no teu livro-razão — são ignoradas em silêncio ao reimportar.',
    'show_errors' => 'Mostrar os erros (:count)',
    'errors_help' => 'Os erros são linhas que não foi possível interpretar; não foram adicionadas ao teu livro-razão.',

    'upload_another' => 'Carregar outro extrato',
];
