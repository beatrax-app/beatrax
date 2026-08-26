<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SALDO INICIAL',
    'confirmed_aria' => 'confirmado',
    'on_date' => 'em :date',

    'detected_h3' => 'Detetámos que :label começou em',
    'confirm' => 'Confirmar',
    'edit' => 'Editar',

    'conflict_h3' => 'Vimos dois valores para esta conta — qual é o correto?',
    'conflict_legend' => 'Escolhe um saldo inicial',
    'conflict_from' => 'De :source:',
    'conflict_helper' => 'Por predefinição usamos a data mais antiga. Escolhe o valor correto ou edita manualmente.',
    'edit_manually' => 'Editar manualmente',

    'editing_h3' => 'Edita o saldo inicial de :label',
    'input_label' => 'SALDO INICIAL',
    'minor_units' => '(unidades menores)',
    'on_date_label' => 'NA DATA',
    'cancel' => 'Cancelar',
    'save' => 'Guardar',

    'change' => 'Alterar',

    'manual_h3' => 'Introduz manualmente o saldo inicial de :label',
    'manual_lede' => 'Não conseguimos detetar automaticamente um saldo inicial para esta conta. Introduz um manualmente ou ignora este passo.',

    'unknown_state' => 'Estado do cartão desconhecido. Recarrega o assistente.',

    'errors' => [
        'account_not_set' => 'Conta não definida. Recarrega o assistente.',
        'invalid_amount' => 'Introduz um montante válido.',
        'amount_range' => 'Introduz um montante entre :min e :max.',
        'pick_date' => 'Escolhe uma data.',
        'pick_valid_date' => 'Escolhe uma data válida.',
        'future_date' => 'A data do saldo inicial não pode estar no futuro.',
        'date_warning' => 'Isto é posterior à tua primeira transação importada (:date). O teu painel pode mostrar transações anteriores a esta data.',
    ],
];
