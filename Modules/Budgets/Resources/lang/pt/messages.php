<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Orçamentos',
        'subtitle' => 'Atribui tudo — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Período anterior',
        'next_aria' => 'Período seguinte',
    ],

    'ready' => [
        'label' => 'Pronto a atribuir',
        'overassigned' => 'Atribuíste mais do que tens — reduz um envelope ou espera por mais receitas.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Ainda não atribuíste nada',
        'copy_hint' => 'Copia o plano do mês passado ou clica numa célula abaixo para começares a atribuir.',
        'copy_hint_touch' => 'Copia o plano do mês passado ou toca numa célula abaixo para começares a atribuir.',
        'first_hint' => 'Clica numa célula abaixo para começares a atribuir o teu primeiro mês.',
        'first_hint_touch' => 'Toca numa célula abaixo para começares a atribuir o teu primeiro mês.',
        'copy_button' => 'Copiar o mês passado',
    ],

    'no_categories' => [
        'heading' => 'Ainda não há categorias de despesa',
        'body' => 'Adiciona uma categoria de despesa para lhe começares a atribuir dinheiro.',
    ],

    'table' => [
        'category' => 'Categoria',
        'assigned' => 'Atribuído',
        'carried_in' => 'Transitado',
        'moved' => 'Movido',
        'spent' => 'Gasto',
        'available' => 'Disponível',
        'if_overspent' => 'Se exceder',
        'notify_at' => 'Notificar aos',
        'actions' => 'Ações',
    ],

    'badge' => [
        'carries_negative' => 'Transita o negativo',
        'unconverted_aria' => 'As despesas numa moeda sem taxa disponível não são contadas aqui — vê o painel',
        'unconverted_title' => 'As despesas sem taxa disponível não são contadas aqui — vê o painel',
        'over_budget' => ':count acima do orçamento',
    ],

    'row' => [
        'assigned_aria' => 'Atribuído para :category',
        'overspend_aria' => 'Se :category exceder o orçamento',
        'notify_aria' => 'Notificar-me na percentagem utilizada para :category',
        'move_money' => 'Mover dinheiro',
        'move' => 'Mover',
    ],

    'overspend' => [
        'reduce' => 'Reduzir o pronto a atribuir do próximo mês',
        'carry' => 'Transitar o negativo neste envelope',
    ],

    'history' => [
        'show' => 'Mostrar histórico ↓',
        'hide' => 'Ocultar histórico ↑',
        'moved_from' => 'Movido de :category',
        'moved_to' => 'Movido para :category',
        'moved_unreadable' => 'Movido com :category por uma versão mais recente do Beatrax',
        'undo' => 'Anular',
    ],

    'phone' => [
        'spent' => 'Gasto :amount',
        'carried_in' => 'Transitado :amount',
        'moved' => 'Movido :amount',
        'available' => 'Disponível :amount',
        'notify_at' => 'Notificar aos',
    ],

    'modal' => [
        'move_from' => 'Mover de :name',
        'move_from_fallback' => 'envelope',
        'move_to' => 'Mover para',
        'no_other' => 'Não há outros envelopes',
        'select' => 'Seleciona um envelope',
        'amount' => 'Montante',
        'available_in' => 'Disponível em :name: :amount',
        'note' => 'Nota (opcional)',
        'note_placeholder' => 'ex.: cobrir o excesso em restauração',
        'cancel' => 'Cancelar',
        'move_funds' => 'Mover fundos',
    ],

    'glance' => [
        'see_all' => 'Ver tudo →',
    ],

    'notices' => [
        'invalid_amount' => 'Introduz um montante válido.',
        'threshold_range' => 'Introduz um número inteiro entre 1 e 200.',
        'copied_last_month' => 'Plano do mês passado copiado.',
        'choose_envelope' => 'Escolhe um envelope para onde mover o dinheiro.',
        'amount_positive' => 'Introduz um montante superior a zero.',
        'move_failed' => 'Não foi possível concluir a movimentação — tenta novamente.',
        'money_moved' => 'Dinheiro movido.',
        'move_undone' => 'Movimentação anulada.',
    ],

    'errors' => [
        'assigned_negative' => 'O montante atribuído não pode ser negativo.',
        'invalid_overspend_mode' => 'Modo de excesso inválido.',
        'threshold_range' => 'O limite de notificação tem de estar entre 1 e 200.',
        'same_envelope' => 'O envelope de origem e o de destino têm de ser diferentes.',
        'non_positive_amount' => 'Montante inválido ou não positivo.',
        'category_not_found' => 'Categoria não encontrada ou não acessível pelo utilizador.',
    ],
];
