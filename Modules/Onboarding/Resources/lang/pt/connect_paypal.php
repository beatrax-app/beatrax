<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A tua conta PayPal',
    'h1' => 'Liga a tua conta PayPal',

    'lede_html' => 'Larga aqui a exportação com os detalhes das transações do PayPal — aparece como <em lang="nl">Rapport Transactiegegevens</em> numa conta PayPal neerlandesa. O relatório de saldo (<span lang="nl">Saldorapport</span>) não serve — precisamos dos dados evento a evento.',

    'format_group_aria' => 'O PayPal exporta apenas em CSV',
    'got_it_as' => 'Obtive-o como:',
    'badge_only_format' => 'único formato',

    'mini' => [
        'login_label' => 'Inicia sessão',
        'custom_label' => 'Extratos personalizados',
        'range_label' => 'Escolhe um período',
        'range_sub' => 'Últimos 12 meses',
        'download_label' => 'Transfere em CSV',
    ],

    'drop_lead' => 'Larga aqui o CSV com os detalhes das transações',
    'browse_file' => 'ou procura um ficheiro',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Ignorar este passo',
    'continue' => 'Continuar →',

    'errors' => [
        'required' => 'Larga primeiro na caixa o CSV «Rapport Transactiegegevens» do PayPal.',
        'max' => 'Esse ficheiro é demasiado grande. As exportações «Rapport Transactiegegevens» do PayPal costumam ficar bem abaixo de 10 MB.',
        'extensions' => 'Esse ficheiro não parece um CSV do PayPal. Transfere do PayPal o «Rapport Transactiegegevens» (não o relatório de saldo «Saldorapport») em CSV.',
        'unreadable' => 'Não foi possível ler este ficheiro. O erro completo está em /dev/logs.',
    ],
];
