<?php

declare(strict_types=1);

return [
    'page_title' => 'Triagem de contrapartes',
    'heading' => 'Triar as contrapartes desconhecidas',

    'progress' => ':seen de :total · :percent% · faltam ~:minutes min',
    'progress_aria' => 'Progresso da triagem',

    'all_caught_aria' => 'Todas as contrapartes estão identificadas',
    'all_caught_heading' => '🎉 Está tudo em dia — todas as contrapartes estão identificadas.',
    'back_to_index' => 'Voltar às contrapartes →',

    'meta' => ':count transação · última vez a :date|:count transações · última vez a :date',

    'suggested_aria' => 'Correspondência sugerida',
    'suggestion_medium' => '✨ Talvez **:name** — confiança média',
    'suggestion_low' => 'Correspondência por padrão: **:name** — confiança baixa. Verifica antes de associar.',
    'suggestion_high' => '✨ Parece ser **:name** — confiança alta',

    'reasoning' => ':hits de :total transação recente neste IBAN aponta para :name.|:hits de :total transações recentes neste IBAN apontam para :name.',
    'yes_link' => 'Sim, associar a :name ↵',
    'no_not' => 'Não, não é :name',

    'recent_on_iban' => 'Transações recentes neste IBAN',
    'recent_on_counterparty' => 'Transações recentes com esta contraparte',
    'no_transactions_yet' => 'Ainda não há transações registadas.',

    'label_manually' => 'Ou identifica manualmente',
    'label_question' => 'O que é esta contraparte?',
    'display_name_label' => 'Nome apresentado',
    'type_label' => 'Tipo',
    'type_merchant' => 'Comerciante',
    'type_personal' => 'Pessoal',
    'type_bank' => 'Banco',
    'type_government' => 'Estado',
    'save_label' => 'Guardar identificação',
    'name_required' => 'Dá primeiro um nome a esta contraparte.',
    'draft_kept' => 'O que escreves fica guardado enquanto percorres a fila.',

    'skip' => 'Ignorar por agora',
    'mark_ignored' => 'Não perguntar mais por esta',
    'skip_note' => 'Ignorar não escreve nada — passa apenas para a desconhecida seguinte.',
    'mark_ignored_note' => 'Isto marca a contraparte como ignorada, para que fique fora desta fila. O nome, o tipo e o histórico ficam intactos e ainda a podes identificar mais tarde na página Contrapartes.',
    'previous' => 'Desconhecida anterior',

    'kbd_yes' => 'sim',
    'kbd_no' => 'não',
    'kbd_skip' => 'ignorar',
    'kbd_next' => 'seguinte',

    'footer' => ':seen já identificadas · faltam :count',
];
