<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor de cenários — :name',
    'rename_aria' => 'Mudar o nome do cenário',
    'save' => 'Guardar',
    'save_changes' => 'Guardar alterações',
    'cancel' => 'Cancelar',
    'rename' => 'Mudar o nome',
    'confirm_delete' => 'Confirmar a eliminação',
    'delete_scenario' => 'Eliminar cenário',
    'delete_confirm' => 'Eliminar este cenário?',

    'mutations_count' => 'Modificações (:count)',
    'no_mutations' => 'Ainda não há modificações. Adiciona uma abaixo para veres como este cenário se compara com a tua referência.',
    'editing' => 'A editar — :kind',
    'edit' => 'Editar',
    'remove' => 'Remover',

    'add_mutation' => '+ Adicionar modificação',
    'add_to_scenario' => 'Adicionar ao cenário',
    'pick_kind' => 'Escolhe um tipo de modificação:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Cancelar uma série',
            'desc' => 'Elimina todas as ocorrências projetadas de uma série aprovada.',
        ],
        'add_one_off' => [
            'title' => 'Adicionar uma cobrança ou um crédito pontual',
            'desc' => 'Um único evento hipotético numa data específica.',
        ],
        'add_recurring' => [
            'title' => 'Adicionar uma série recorrente',
            'desc' => 'Uma nova subscrição ou fonte de rendimento hipotética.',
        ],
        'change_series_amount' => [
            'title' => 'Alterar o montante de uma série',
            'desc' => 'Simula um aumento ou uma descida de preço numa série existente.',
        ],
        'shift_series_date' => [
            'title' => 'Deslocar a data de uma série',
            'desc' => 'Adia a próxima ocorrência ou todas as seguintes.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Série a cancelar',
        'pick_series' => '— escolhe uma série —',
        'date' => 'Data',
        'amount' => 'Montante',
        'currency' => 'Moeda',
        'direction' => 'Sentido',
        'expense_long' => 'Despesa (dinheiro a sair)',
        'income_long' => 'Rendimento (dinheiro a entrar)',
        'note' => 'Nota (opcional)',
        'start_date' => 'Data de início',
        'expense' => 'Despesa',
        'income' => 'Rendimento',
        'cadence' => 'Periodicidade',
        'cadence_weekly' => 'Semanal',
        'cadence_monthly' => 'Mensal',
        'cadence_quarterly' => 'Trimestral',
        'cadence_yearly' => 'Anual',
        'series' => 'Série',
        'new_amount' => 'Novo montante',
        'new_next_date' => 'Nova data seguinte',
        'scope' => 'Âmbito',
        'scope_legend' => 'Que ocorrências deslocar',
        'scope_next' => 'Apenas a próxima ocorrência',
        'scope_all' => 'Todas as ocorrências seguintes',
    ],

    'whatif' => [
        'trigger' => 'Simular hipótese',
        'menu_aria' => 'Simular hipótese para :name',
        'model_cancellation' => 'Simular o cancelamento',
        'model_amount_change' => 'Simular a alteração do montante…',
        'amount_dialog_aria' => 'Simular a alteração do montante para :name',
        'current_amount' => 'Montante atual',
        'new_amount' => 'Novo montante',
    ],

    'summary' => [
        'cancel' => 'Cancelar :name',
        'series_fallback' => 'série n.º :id',
        'one_off' => ':amount :currency a :date',
        'recurring' => ':amount :currency :cadence a partir de :date',
        'change_amount' => ':name: novo montante :amount',
        'shift' => ':name: deslocar :scope para :date',
        'scope_all' => 'todas as seguintes',
        'scope_next' => 'a seguinte',
    ],

    'toast' => [
        'created' => 'Cenário ":name" criado.',
        'deleted' => 'Cenário eliminado.',
        'renamed' => 'Nome do cenário alterado.',
        'mutation_added' => 'Modificação adicionada.',
        'mutation_updated' => 'Modificação atualizada.',
        'mutation_removed' => 'Modificação removida. Anular',
    ],

    'errors' => [
        'name_empty' => 'O nome do cenário não pode estar vazio.',
        'name_too_long' => 'O nome do cenário não pode ter mais de :max caracteres.',
        'name_taken' => 'Já existe um cenário com esse nome.',
        'pick_kind_first' => 'Escolhe primeiro um tipo de modificação.',
        'amount_positive' => 'O montante tem de ser um número positivo.',
    ],
];
