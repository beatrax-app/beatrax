<?php

declare(strict_types=1);

return [
    'page_title' => 'Regras',
    'heading' => 'Regras',
    'intro' => 'Pré-categoriza as transações na importação. As regras aplicam-se a todas as origens — banco, cartão, PayPal e recibos de e-mail.',
    'device_local_note' => 'As regras ficam neste dispositivo. Não são partilhadas com os teus outros dispositivos.',

    'reapply' => 'Reaplicar as regras ao histórico',
    'reapply_confirm' => 'Reaplicar todas as regras a todo o teu histórico? Cada categoria, contraparte, nota e etiqueta fiscal que uma regra tenha colocado é reescrita. O que definiste à mão mantém-se, tal como tudo o que esteja num extrato reconciliado ou numa transação que tenhas dividido. Nada repõe os valores antigos.',
    'reapplying' => 'A reaplicar…',
    'new_rule' => 'Nova regra',

    'reapply_progress' => 'A reaplicar as regras… :checked de :count transação verificada|A reaplicar as regras… :checked de :count transações verificadas',

    'empty_heading' => 'Ainda não há regras',
    'empty_body' => 'As regras encontram transações através de várias condições e aplicam automaticamente alterações de categoria, contraparte, nota e etiqueta fiscal — na importação e sempre que as reaplicares ao teu histórico existente.',
    'empty_cta' => 'Cria a tua primeira regra',

    'col_priority' => 'Prioridade',
    'col_conditions' => 'Condições',
    'col_actions' => 'Ações',
    'col_hits' => 'Ocorrências',
    'col_created' => 'Criada',
    'col_row_actions' => 'Ações',
    'inactive_badge' => 'Inativa',
    'combinator_all' => 'TODAS',
    'combinator_any' => 'QUALQUER',
    'inactive_title' => 'Esta regra não é aplicada. Uma regra é desativada quando a categoria ou a contraparte a que aponta é eliminada.',

    'more_conditions' => '+:count mais',

    'delete_confirm' => 'Eliminar?',
    'delete_yes' => 'Sim, eliminar',
    'cancel' => 'Cancelar',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'edit_aria' => 'Editar a regra (prioridade :priority)',
    'delete_aria' => 'Eliminar a regra (prioridade :priority)',

    'footer_note' => 'As regras e o histórico de comerciantes funcionam em conjunto. Eliminar uma regra não apaga o que o Beatrax aprendeu com categorizações anteriores — a próxima importação pode continuar a sugerir a mesma categoria a partir do histórico.',

    'chip_category' => 'Categoria: :path',
    'chip_counterparty' => 'Contraparte: :path',
    'chip_note' => 'Nota',
    'chip_tax_tag' => 'Etiqueta fiscal',

    'flash_deleted' => 'Regra eliminada.',
    'flash_not_found' => 'Regra não encontrada (pode ter sido eliminada noutro separador).',
    'flash_saved' => 'Regra guardada.',
    'flash_reapplying' => 'A reaplicar as regras ao teu histórico…',
    'summary_no_changes' => 'Sem alterações — o teu histórico já corresponde às tuas regras.',
    'summary_updated' => 'Atualizados :fields em :transactions.',
    'summary_fields' => ':count campo|:count campos',
    'summary_transactions' => ':count transação|:count transações',
    'summary_reconciled_skipped' => ':count transação reconciliada foi ignorada.|:count transações reconciliadas foram ignoradas.',
];
