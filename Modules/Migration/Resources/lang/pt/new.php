<?php

declare(strict_types=1);

return [
    'page_title' => 'Importar do YNAB / Actual',

    'eyebrow' => 'Migrações',
    'heading' => 'Importar do YNAB / Actual',
    'intro' => 'Traz a tua árvore de categorias, o histórico de orçamentos e as transações do YNAB4, do novo YNAB ou do Actual Budget. Nada é escrito no teu livro-razão até reveres e confirmares.',
    'reconcile_context' => 'A procurar novidades face à tua última importação de :product.',

    'source_label' => 'Origem',
    'file_label' => 'Ficheiro',
    'parse_button' => 'Analisar a exportação',

    'hints' => [
        'ynab4' => 'Exporta o teu orçamento completo como ficheiro ZIP a partir do menu File → Export do YNAB4.',
        'nynab' => 'Exporta o teu orçamento do nYNAB através de File → Export Budget e depois comprime num ZIP os ficheiros CSV exportados.',
        'actual' => 'Exporta o teu orçamento como ficheiro ZIP a partir de Settings → Export data do Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Isto não parece uma exportação do YNAB4, do nYNAB ou do Actual que consigamos ler. Verifica o ficheiro e tenta novamente.',
        'file_too_large' => 'Esse ficheiro é demasiado grande para uma exportação de migração.',
        'archive_reader_unavailable' => 'Esta versão da aplicação não tem qualquer leitor ZIP capaz de abrir esta exportação, por isso não é possível lê-la aqui. Importa-a na aplicação de computador, ou volta a comprimir a exportação com compressão comum.',
        'internal_detail' => 'A aplicação não conseguiu ler esta exportação (:code). Os detalhes completos estão no registo da aplicação; indica este código se comunicares um problema.',
    ],
];
