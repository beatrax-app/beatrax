<?php

declare(strict_types=1);

return [

    'reconciled_lock' => 'Esta transação está reconciliada. Anula a reconciliação para fazeres alterações.',
    'tagged' => 'Etiquetada como dedutível.',
    'untagged' => 'Etiqueta fiscal removida.',
    'batch_none_reconciled' => 'Nada foi etiquetado — essas transações estão reconciliadas. Anula a reconciliação para fazeres alterações.',
    'batch_tagged' => 'Foi etiquetada mais :count transação.|Foram etiquetadas mais :count transações.',

    'errors' => [
        'name_empty' => 'O nome da categoria não pode estar vazio.',
        'name_duplicate' => 'Já existe uma categoria com este nome.',
        'category_not_saved' => 'Não foi possível guardar esta categoria. Tente novamente.',
        'tag_refused' => 'Não foi possível guardar esta etiqueta. Feche o seletor e tente novamente.',
    ],
];
