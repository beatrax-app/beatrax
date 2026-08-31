<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliases',
    'heading' => 'Aliases',
    'subtitle' => 'Nomes legíveis que ensinaste ao Beatrax para as descrições crípticas dos teus extratos. Edita o padrão generalizado de uma linha para alargar ou restringir quais as outras transações que herdam o mesmo nome legível.',
    'dismiss' => 'dispensar',

    'selected_count' => ':count selecionados',
    'merge_selected' => 'Unir os selecionados',

    'empty_heading' => 'Ainda não há aliases',
    'empty_body' => 'Os aliases aparecem aqui depois de clicares na descrição original em itálico de uma linha da pré-visualização da importação e lhe dares um nome legível.',
    // i18n-review: pt · empty_body_touch — the same line for a touch
    // screen; check the verb governs this case.
    'empty_body_touch' => 'Os aliases aparecem aqui depois de tocares na descrição original em itálico de uma linha da pré-visualização da importação e lhe dares um nome legível.',

    'col_select' => 'Selecionar',
    'col_raw' => 'Descrição original',
    'col_generalized' => 'Padrão generalizado',
    'col_friendly' => 'Nome legível',
    'col_actions' => 'Ações',

    'select_alias_aria' => 'Selecionar o alias :name',
    'generalized_pattern_aria' => 'Padrão generalizado',

    'save' => 'Guardar',
    'cancel' => 'Cancelar',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'delete_confirm' => 'Eliminar este alias? As próximas importações de «:pattern» voltam a usar a descrição original.',

    'backup_transfer' => 'Cópia de segurança e transferência',
    'export_yaml' => 'Exportar os aliases como YAML',

    'export_help_html' => 'Transfere <code class="font-mono">aliases.yaml</code> no formato do corpus da comunidade.',
    'import_from_yaml' => 'Importar de YAML',
    'parse_preview' => 'Interpretar e pré-visualizar',
    'cancel_import' => 'Cancelar a importação',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    // i18n-review: pt · diff_unchanged — sem alterações is invariable, so both arms
    // repeat it and the singular reads terse beside novo. The participle
    // inalterado would agree but drops the wording this file already had.
    'diff_new' => ':count novo|:count novos',
    'diff_unchanged' => ':count sem alterações|:count sem alterações',
    'diff_conflicts' => ':count conflito|:count conflitos',

    'conflicts_heading' => 'Conflitos',
    'conflict_name' => 'nome — atual: :existing → ficheiro: :file',
    'conflict_pattern_existing' => 'padrão — atual:',
    'conflict_file' => '→ ficheiro:',
    'resolution_for_aria' => 'Resolução para :pattern',
    'keep_yours' => 'Manter o teu',
    'replace' => 'Substituir',
    'confirm_import' => 'Confirmar a importação',

    'preview_aria' => 'Pré-visualização com as transações',
    'test_heading' => 'Testar com as minhas transações',
    'test_help' => 'Edita o padrão generalizado de uma linha para veres com que transações corresponderia.',
    'typing' => 'A escrever…',
    'matches' => 'Corresponde a :count transação do teu histórico recente.|Corresponde a :count transações do teu histórico recente.',

    'merge_modal_title' => 'Unir :count alias|Unir :count aliases',

    'merge_modal_help_html' => 'A linha que fica mantém a sua descrição original; as linhas absorvidas são preservadas em <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nome legível',
    'generalized_pattern_label' => 'Padrão generalizado',
    'no_prefix_warning' => 'Não foi encontrado nenhum prefixo comum de 4 caracteres entre os aliases selecionados — escreve um padrão à mão antes de confirmar.',
    'confirm_merge' => 'Confirmar a união',

    'flash' => [
        'updated' => 'Alias atualizado.',
        'deleted' => 'Alias eliminado.',
        'merged' => 'Aliases unidos.',
        'imported' => ':count alias importado.|:count aliases importados.',
        'nothing' => 'Não há nada para importar.',
    ],

    'errors' => [
        'not_found' => 'Alias não encontrado (pode ter sido eliminado noutro separador).',
        'pattern_empty' => 'O padrão generalizado não pode estar vazio.',
        'select_two' => 'Seleciona pelo menos dois aliases para os unir.',
        'some_not_found' => 'Não foram encontrados um ou mais dos aliases selecionados.',
        'both_required' => 'O nome legível e o padrão generalizado são ambos obrigatórios.',
        'merge_not_found' => 'Não foram encontrados um ou mais aliases (podem ter sido eliminados noutro separador).',
        'merge_failed' => 'A união falhou (:class).',
        'no_file' => 'Não foi carregado nenhum ficheiro.',
        'unreadable' => 'Não foi possível ler o ficheiro carregado.',
        'too_short' => 'O padrão é demasiado curto para ser testado.',
        'file_not_yaml' => 'Este ficheiro não é YAML válido, por isso não foi possível ler nada dele. Exporta os teus aliases outra vez e carrega o ficheiro que obtiveres.',
        'file_unreadable_as_yaml' => 'Não foi possível ler este ficheiro como uma lista de aliases. Exporta os teus aliases outra vez e carrega o ficheiro que obtiveres.',
        'file_has_no_entries_list' => 'Este ficheiro não começa por uma lista entries: de primeiro nível, por isso não tem aliases para importar. Verifica se escolheste o ficheiro certo.',
        'entry_is_not_a_mapping' => 'A entrada :entry é um valor simples onde eram esperados um padrão e um nome. Dá-lhe os dois campos, ou remove-a, e carrega o ficheiro outra vez.',
        'entry_is_missing_a_field' => 'À entrada :entry falta o padrão ou o nome, e um alias precisa dos dois. Preenche o que falta, ou remove essa entrada, e carrega o ficheiro outra vez.',
    ],
];
