<?php

declare(strict_types=1);

return [
    'page_title' => 'Livro de caixa',
    'heading' => 'Livro de caixa',
    'intro' => 'Regista à mão as despesas em numerário e outras fora do banco. Os lançamentos manuais entram no mesmo livro-razão que as tuas importações — são categorizados, entram na deteção de recorrências e contam para o teu mês.',

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
    'delete' => 'Eliminar',
    'delete_confirm' => 'Eliminar este lançamento?',
    'delete_keep' => 'Manter',

    'errors' => [
        'amount_positive' => 'Introduz um montante superior a zero.',
        'amount_too_large' => 'Esse montante é demasiado elevado. Verifica os dígitos.',
        'amount_unreadable' => 'Não foi possível ler este montante. Introduza-o sem separador de milhares e com um máximo de duas casas decimais, por exemplo :example.',
        'invalid_date' => 'Introduz uma data válida.',
    ],

    'toast' => [
        'added' => 'Lançamento de caixa adicionado.',
        'removed' => 'Lançamento de caixa removido.',
    ],
];
