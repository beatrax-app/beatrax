<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Objetivos',
        'subtitle' => 'Acompanha o progresso das tuas metas de poupança.',
        'add_goal' => 'Adicionar objetivo',
    ],

    'empty' => [
        'heading' => 'Ainda não há objetivos',
        'body' => 'Define um montante-alvo e uma data para começares a acompanhar o teu progresso de poupança.',
        'add_first' => 'Adiciona o teu primeiro objetivo',
    ],

    'status' => [
        'overdue' => 'Em atraso',
        'reached' => 'Atingido',
        'completed' => 'Concluído',
        'archived' => 'Arquivado',
    ],

    'row' => [
        'edit' => 'Editar',
    ],

    'progress' => [
        'aria' => ':name: :pct% concluído',
    ],

    'card' => [
        'target_date' => 'Data-alvo: :date',
    ],

    'projection' => [
        'target_reached' => 'Meta atingida',
        'closed_short' => 'Fechado antes do objetivo',
        'add_contributions' => 'Adiciona contribuições para veres uma projeção',
        'not_enough_history' => 'Ainda não há histórico suficiente para projetar uma data',
        'no_recent_contributions' => 'Sem contribuições recentes para projetar uma data',
        'est' => 'Est. :date ·',
        'projection_note' => '(projeção)',
        'projected' => 'Projetado: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arquivar este objetivo?',
        'close' => 'Fechar',
        'confirm_aria' => 'Confirmar o arquivamento de :name',
        'archive' => 'Arquivar',
    ],

    'actions' => [
        'more_aria' => 'Mais ações para :name',
        'mark_complete' => 'Marcar como concluído',
        'archive' => 'Arquivar',
        'restore' => 'Restaurar',
    ],

    'archived_disclosure' => 'Objetivos arquivados (:count)',

    'form' => [
        'title_edit' => 'Editar objetivo',
        'title_create' => 'Criar um objetivo de poupança',
        'subtitle_edit' => 'Atualiza o nome, a meta, a data ou a reserva associada.',
        'subtitle_create' => 'Define um montante-alvo e uma data para acompanhares o teu progresso de poupança.',
        'name' => 'Nome',
        'name_placeholder' => 'ex.: Fundo de emergência',
        'target_amount' => 'Montante-alvo (:currency)',
        'target_date' => 'Data-alvo',
        'linked_pot' => 'Reserva associada (opcional)',
        'no_pot' => 'Sem reserva — usar o acompanhamento de transferências',
        'linked_pot_help' => 'Quando associada, o saldo da reserva determina o progresso deste objetivo.',
        'save_changes' => 'Guardar alterações',
        'save_goal' => 'Guardar objetivo',
        'close' => 'Fechar',
    ],

    'summary' => [
        'see_all' => 'Ver todos →',
        'no_goals' => 'Ainda não há objetivos.',
        'add_first' => 'Adiciona o teu primeiro objetivo →',
    ],

    'notices' => [
        'goal_created' => 'Objetivo criado.',
        'goal_updated' => 'Objetivo atualizado.',
        'goal_marked_complete' => 'Objetivo marcado como concluído.',
        'goal_archived' => 'Objetivo arquivado.',
        'goal_restored' => 'Objetivo restaurado.',
    ],

    'errors' => [
        'name' => 'Introduz um nome para o teu objetivo.',
        'date' => 'Escolhe uma data-alvo.',
        'amount' => 'Introduz um montante válido superior a zero.',
        'pot_linked_category' => 'Esta reserva está associada a uma categoria. Remove primeiro essa associação na página Reservas.',
    ],
];
