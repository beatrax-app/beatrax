<?php

declare(strict_types=1);

return [
    'page_title' => 'Transação',
    'heading' => 'Transação',
    'booked_on' => 'Lançado a :date',

    'counterparty' => 'Contraparte',
    'description' => 'Descrição',
    'amount_native' => 'Montante (moeda original)',
    'amount_settled' => 'Montante (liquidado)',
    'effective_rate' => 'Taxa efetiva',
    'ics_markup' => 'Inclui qualquer margem da ICS.',

    'split' => [
        'category' => 'Categoria',
        'open' => 'Dividir por categorias',
        'heading' => 'Dividir por várias categorias',
        'total' => 'Total :amount',
        'tax_per_category' => 'As etiquetas fiscais são definidas por categoria em baixo.',
        'choose_category' => 'Escolhe uma categoria',
        'note_label' => 'Nota',
        'note_placeholder' => 'Nota (opcional)',
        'tax_deductible' => 'Dedutível',
        'remove_leg_aria' => 'Remover esta categoria',
        'remove_leg_caption' => 'Remover',
        'add_category' => '+ Adicionar categoria',
        'soft_cap' => ':count de ~20 categorias — considera agrupar os montantes pequenos.',
        'remaining_zero' => 'Restante :amount ✓',
        'remaining_to_assign' => 'Falta atribuir: :amount',
        'over_allocated' => 'Excedido em :amount — reduz uma parcela.',
        'save' => 'Guardar a divisão',
        'saving' => 'A guardar…',
        'unsplit' => 'Anular a divisão da transação',
        'remove_to_one' => 'Ao remover esta, fica só uma categoria — a transação passa a :category.',
        'remove_to_one_fallback' => 'esta categoria',
        'remove_category' => 'Remover categoria',
        'keep_category' => 'Manter esta categoria',
        'restore_single' => 'Restaurar como categoria única?',
        'survivor_legend' => 'Categoria a manter',
        'confirm_unsplit' => 'Sim, anular a divisão',
        'keep_split' => 'Manter a divisão',
    ],

    'tax' => [
        'section_aria' => 'Etiqueta fiscal',
        'label' => 'Dedutível',
    ],

    'reclassify' => [
        'heading' => 'Reclassificar',
        'help' => 'Substitui o tipo detetado. Se esta transação estiver emparelhada com outra, escolher um tipo que não seja transferência desfaz o emparelhamento dos dois lados.',
        'choose_aria' => 'Escolher o novo tipo de transação',
        'choose_option' => 'Escolhe um tipo…',
        'save' => 'Guardar',
    ],

    'type_label' => [
        'expense' => 'Despesa',
        'income' => 'Receita',
        'transfer_out' => 'Transferência enviada',
        'transfer_in' => 'Transferência recebida',
        'fee' => 'Taxa',
        'refund' => 'Reembolso',
        'adjustment' => 'Ajuste',
    ],

    'note' => [
        'heading' => 'Nota',
        'help' => 'Nota pessoal para esta transação. Visível apenas para ti.',
        'label' => 'Nota',
        'placeholder' => 'Adicionar uma nota…',
        'save' => 'Guardar a nota',
        'saved' => 'Guardada',
    ],

    'reassign' => [
        'heading' => 'Reatribuir a contraparte',
        'help' => 'Substitui a contraparte identificada para esta transação.',
        'choose_aria' => 'Escolher a contraparte',
        'choose_option' => 'Escolhe uma contraparte…',
        'submit' => 'Reatribuir',
    ],

    'goal' => [
        'heading' => 'Objetivo de poupança',
        'help' => 'Conta esta transação para um dos teus objetivos de poupança.',
        'choose_aria' => 'Escolhe um objetivo de poupança',
        'choose_option' => 'Escolhe um objetivo…',
        'submit' => 'Adicionar ao objetivo',
        'remove_aria' => 'Remover :name',
    ],

    'delete' => [
        'heading' => 'Eliminar a transação',
        'help' => 'Remove permanentemente esta transação. Esta ação não pode ser anulada.',
        'button' => 'Eliminar',
        'confirm_prompt' => 'Eliminar esta transação? A nota, a divisão e as etiquetas fiscais vão com ela.',
        'confirm' => 'Sim, eliminar',
        'cancel' => 'Cancelar',
    ],

    'chain' => [
        'view' => 'Ver a cadeia',
    ],

    'unreconcile' => [
        'heading' => 'Reconciliada e bloqueada',
        'help' => 'Uma reconciliação concluída bloqueou esta transação. A categoria, a nota, a divisão e as etiquetas fiscais ficam como estão até a desbloqueares.',
        'button' => 'Desbloquear para editar',
        'confirm_question' => 'Desbloquear esta transação para a editar? Nada nela muda, e a próxima reconciliação concluída volta a bloqueá-la.',
        'cancel' => 'Deixar bloqueada',
    ],

    'toast' => [
        'reconciled_locked' => 'Esta transação está reconciliada. Anula a reconciliação para fazeres alterações.',
        'reclassified_pair_removed' => 'Reclassificada como :type — emparelhamento removido',
        'reclassified' => 'Reclassificada como :type',
        'note_saved' => 'Nota guardada',
        'unreconciled' => 'Reconciliação anulada — podes voltar a editar esta transação.',
        'note_too_long' => 'Uma nota tem no máximo :max carácter.|Uma nota tem no máximo :max caracteres.',
        'counterparty_updated' => 'Contraparte atualizada',
        'goal_attributed' => 'Contabilizado neste objetivo',
        'goal_attribution_removed' => 'Já não é contabilizado neste objetivo',
        'split_saved' => 'Divisão guardada',
        'removed_one_remains' => 'Removida — resta uma categoria',
        'unsplit_restored' => 'Divisão anulada — restaurada como categoria única',
    ],

    'errors' => [
        'totals_must_match' => 'Não foi possível guardar — o total das parcelas tem de corresponder exatamente ao total da transação.',
        'not_found' => 'Transação não encontrada.',
        'amount_zero' => 'O montante não pode ser :amount',
        'choose_category' => 'Escolhe uma categoria.',
        'choose_before_removing' => 'Escolhe uma categoria antes de remover.',
        'choose_before_unsplitting' => 'Escolhe uma categoria antes de anular a divisão.',
        'not_found_or_unowned' => 'Transação não encontrada ou não pertencente ao utilizador.',
        'reconciled_split' => 'Esta transação está reconciliada. Anula a reconciliação para alterares a divisão.',
        'not_splittable' => "O tipo de transação ':type' não é divisível.",
        'min_two_legs' => 'Uma divisão exige pelo menos 2 parcelas.',
        'legs_non_zero' => 'Os montantes das parcelas não podem ser zero.',
        'legs_parent_sign' => 'Os montantes das parcelas têm de ter o mesmo sinal do montante original.',
        'leg_category_not_accessible' => 'Categoria da parcela não encontrada ou não acessível ao utilizador.',
        'survivor_not_accessible' => 'Categoria remanescente não encontrada ou não acessível ao utilizador.',
        'survivor_must_be_current' => 'A categoria remanescente tem de ser uma das categorias atuais das parcelas da divisão.',
    ],
];
