<?php

declare(strict_types=1);

return [
    'page_title' => 'Livro de caixa',
    'heading' => 'Livro de caixa',
    'intro' => 'Regista à mão as despesas em numerário e outras fora do banco. Os lançamentos manuais entram no mesmo livro-razão que as tuas importações — são categorizados, associados a uma contraparte, entram na deteção de recorrências e contam para o teu mês.',

    'direction' => 'Sentido',
    'expense' => 'Despesa',
    'income' => 'Receita',

    'amount' => 'Montante (:symbol)',
    'date' => 'Data',
    'counterparty' => 'Contraparte',
    'counterparty_placeholder' => 'ex.: Padaria',
    'category' => 'Categoria',
    'optional' => '(opcional)',
    'uncategorized' => 'Sem categoria',
    'note' => 'Nota',

    'add_entry' => 'Adicionar lançamento',
    'manual_entries' => 'Lançamentos manuais',
    'no_entries' => 'Ainda não há lançamentos manuais.',
    'delete_entry' => 'Eliminar lançamento',
    'delete_entry_caption' => 'Eliminar',
    'delete' => 'Eliminar',
    'delete_confirm' => 'Eliminar este lançamento?',
    'delete_keep' => 'Manter',

    'errors' => [
        'amount_positive' => 'Introduz um montante superior a zero.',
        'amount_too_large' => 'Esse montante é demasiado elevado. Verifica os dígitos.',
        'amount_unreadable' => 'Não foi possível ler o montante. Introduz-o com no máximo :decimals casa decimal, por exemplo :example.|Não foi possível ler o montante. Introduz-o com no máximo :decimals casas decimais, por exemplo :example.',
        'amount_unreadable_whole' => 'Não foi possível ler o montante. Esta moeda não tem casas decimais, por isso introduz um número inteiro, por exemplo :example.',
        'invalid_date' => 'Introduz uma data válida.',
        'not_recorded' => 'O lançamento não foi registado. Tenta adicioná-lo novamente.',
    ],

    'toast' => [
        'added' => 'Lançamento de caixa adicionado.',
        'removed' => 'Lançamento de caixa removido.',
        'reconciled_locked' => 'Esta transação está reconciliada. Anula a reconciliação para fazeres alterações.',
    ],
];
