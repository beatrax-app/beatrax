<?php

declare(strict_types=1);

return [
    'page_title' => 'Pré-visualizar a importação',

    'heading' => 'Pré-visualizar a importação',
    'subtitle' => 'Revê o que vai mudar. Nada é guardado até confirmares.',

    'stats' => [
        'category' => 'Categorias',
        'account' => 'Contas',
        'payee' => 'Contrapartes',
        'transaction' => 'Transações',
        'budget' => 'Meses de orçamento',
    ],

    'all_clean' => 'Está tudo mapeado sem problemas — não há aqui nada para decidires.',

    'nothing_staged' => 'Esta exportação não continha nada para importar — não há nada a confirmar aqui.',

    'groups' => [
        'conflict' => 'Precisa da tua decisão',
        'extra' => 'Não importado',
    ],

    'keep_or_take_aria' => 'Manter o local ou usar a origem para :label',
    'keep_local' => 'Manter o local',
    'take_source' => 'Usar a origem',

    'footer_note' => 'Isto vai criar ou atualizar as quantidades indicadas acima nas tuas categorias, orçamentos e livro-razão.',
    'discard_button' => 'Descartar a importação',
    'discard_confirm' => 'Descartar esta importação? Tudo o que foi lido do teu ficheiro de exportação é apagado aqui, e recuperá-lo implica carregar e processar o ficheiro inteiro outra vez. Ainda não chegou nada ao teu livro-razão.',
    'confirm_button' => 'Confirmar a importação',
];
