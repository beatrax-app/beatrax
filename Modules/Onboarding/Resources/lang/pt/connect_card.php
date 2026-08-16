<?php

declare(strict_types=1);

return [
    'eyebrow' => 'O teu cartão de crédito (ICS)',
    'h1' => 'Vai buscar os PDF dos teus extratos mensais',
    'lede' => 'Larga todos os PDF dos teus extratos mensais da ICS — juntamo-los numa só pré-visualização.',

    'format_group_aria' => 'A ICS exporta apenas em PDF',
    'got_it_as' => 'Obtive-o como:',
    'badge_only_format' => 'único formato',

    'mini' => [
        'login_label' => 'Inicia sessão',
        'statements_label' => 'Abre os extratos',
        'months_label' => 'Escolhe os meses',
        'months_sub' => 'Um PDF por mês',
        'download_label' => 'Transfere',
    ],

    'drop_lead' => 'Larga aqui os teus PDF da ICS',
    'browse_files' => 'ou procura ficheiros',
    'queue_aria' => 'Extratos PDF em fila',

    'skip' => 'Ignorar este passo',
    'continue' => 'Continuar →',

    'errors' => [
        'required' => 'Larga os extratos mensais em PDF que transferiste do Mijn ICS.',
        'min' => 'Larga pelo menos um extrato da ICS em PDF antes de continuar.',
        'each_required' => 'Larga o extrato mensal em PDF que transferiste do Mijn ICS.',
        'each_max' => 'Um dos teus ficheiros é demasiado grande. Os extratos da ICS em PDF costumam ter menos de 1 MB cada.',
        'each_extensions' => 'Um dos teus ficheiros não é um PDF. O Mijn ICS só exporta PDF — experimenta o extrato mensal mais recente.',
        'file_unreadable' => 'Não foi possível ler :filename. O erro completo está em /dev/logs.',
        'none_readable' => 'Não conseguimos ler nenhum dos teus PDF da ICS. :detail',
        'full_error_in_logs' => 'O erro completo está em /dev/logs.',
    ],
];
