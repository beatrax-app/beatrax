<?php

declare(strict_types=1);

return [
    'page_title' => 'Reservas · Beatrax',
    'heading' => 'Reservas',
    'subtitle' => 'Subsaldos virtuais separados do saldo real da conta.',
    'add_pot' => 'Adicionar reserva',

    'pot_fallback' => 'reserva',

    'empty' => [
        'heading' => 'Ainda não há reservas',
        'body' => 'Cria subsaldos virtuais dentro de qualquer conta para organizares o teu dinheiro sem uma transferência bancária real.',
        'cta' => 'Adiciona a tua primeira reserva',
        'no_accounts_cta' => 'Importar um extrato',
    ],

    'common' => [
        'cancel' => 'Cancelar',
        'amount' => 'Montante',
        'note_optional' => 'Nota (opcional)',
    ],

    'actions' => [
        'fund' => 'Depositar',
        'move' => 'Mover',
        'edit' => 'Editar',
        'withdraw' => 'Levantar',
        'archive' => 'Arquivar',
        'restore' => 'Restaurar',
    ],

    'recon' => [
        'over_allocated' => 'As reservas excedem o saldo real em :amount — reequilibra para corrigir',
        'real_balance' => 'Saldo real:',
        'allocated' => 'Alocado:',
        'unallocated' => 'Não alocado:',
    ],

    'chip' => [
        'goal' => 'Objetivo:',
        'goal_name_fallback' => 'Objetivo',
        'category_fallback' => 'Categoria',
    ],

    'coverage' => [
        'spent' => 'gasto',
        'in_pot' => 'na reserva',
    ],

    'archive_confirm' => 'Arquivar esta reserva? O saldo de :amount volta para não alocado.',
    'confirm_archive_aria' => 'Confirmar o arquivamento de :name',
    'more_actions_aria' => 'Mais ações para :name',

    'history' => [
        'show' => 'Mostrar o histórico ↓',
        'hide' => 'Ocultar o histórico ↑',
        'truncated' => 'Movimentos recentes: :shown de :count',
    ],

    'movement' => [
        'fund' => 'Depósito',
        'withdraw' => 'Levantamento',
        'moved_from' => 'Movido de :name',
        'moved_to' => 'Movido para :name',
        'unreadable' => 'Registado por uma versão mais recente do Beatrax',
        'released_on_archive' => 'Libertado ao arquivar',
    ],

    'archived' => [
        'toggle' => 'Reserva arquivada (:count)|Reservas arquivadas (:count)',
        'badge' => 'Arquivada',
    ],

    'form' => [
        'create_title' => 'Criar uma reserva',
        'edit_title' => 'Editar reserva',
        'create_subtitle' => 'Dá um nome a um subsaldo virtual dentro de uma conta.',
        'edit_subtitle' => 'Atualiza o nome ou a associação desta reserva.',
        'name' => 'Nome',
        'name_placeholder' => 'ex.: Fundo de férias',
        'account' => 'Conta',
        'select_account' => 'Seleciona uma conta',
        'initial_amount' => 'Montante inicial (opcional)',
        'initial_amount_help' => 'O montante é deduzido do não alocado. Deixa em branco para criar vazia.',
        'link_to' => 'Associar a (opcional)',
        'link_goal' => 'Objetivo',
        'link_none' => 'Nenhum',
        'select_goal' => 'Seleciona um objetivo',
        'save_pot' => 'Guardar reserva',
        'save_changes' => 'Guardar alterações',
    ],

    'fund' => [
        'title' => 'Depositar na reserva',
        'heading' => 'Depositar em :name',
        'submit' => 'Depositar na reserva',
        'note_placeholder' => 'ex.: Poupança mensal',
        'available' => 'Disponível para alocar: :amount (não alocado)',
    ],

    'move' => [
        'title' => 'Mover fundos',
        'heading' => 'Mover de :name',
        'to' => 'Mover para',
        'select_pot' => 'Seleciona uma reserva',
        'no_others_short' => 'Sem outras reservas',
        'no_others' => 'Não há outras reservas nesta conta',
        'submit' => 'Mover fundos',
        'note_placeholder' => 'ex.: Transferência para as férias',
    ],

    'withdraw' => [
        'heading' => 'Levantar de :name',
        'note_placeholder' => 'ex.: Levantamento',
    ],

    'available_in' => 'Disponível em :name: :amount',

    'errors' => [
        'enter_name' => 'Introduz um nome para esta reserva.',
        'select_account' => 'Seleciona uma conta para esta reserva.',
        'amount_exceeds_unallocated_available' => 'O montante excede o saldo não alocado (:amount disponível).',
        'amount_exceeds_pot_balance' => 'O montante excede o saldo em :name (:amount disponível).',
        'generic' => 'Não foi possível guardar o mealheiro. Verifique os campos e tente novamente.',
        'amount_invalid' => 'Introduza um valor maior que zero.',
        'goal_already_linked' => 'Este objetivo já tem um mealheiro associado ativo. Arquive-o primeiro.',
        'account_cannot_hold_pots' => 'Uma reserva precisa de uma conta com dinheiro. Escolhe outra conta.',
        'select_target_pot' => 'Seleciona uma reserva para onde mover.',
        'move_target_missing' => 'Essa reserva já não está disponível. Escolhe outra.',
        'move_same_pot' => 'Uma reserva não pode mover dinheiro para si própria. Escolhe outra reserva.',
        'move_cross_account' => 'As reservas só trocam dinheiro dentro da mesma conta, e :name está em :account.',
        'pot_missing' => 'Essa reserva já não está disponível.',
        'operation_failed' => 'Não foi concluído. Não se moveu dinheiro — tenta novamente.',
    ],

    'toast' => [
        'pot_created' => 'Reserva criada.',
        'pot_updated' => 'Reserva atualizada.',
        'pot_funded' => 'Depósito efetuado.',
        'withdrawn' => 'Levantado da reserva.',
        'funds_moved' => 'Fundos movidos.',
        'pot_archived' => 'Reserva arquivada.',
        'pot_restored' => 'Reserva restaurada.',
    ],
];
