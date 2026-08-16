<?php

declare(strict_types=1);

return [
    'eyebrow' => 'O teu banco',
    'h1' => 'Vai buscar um extrato e larga-o aqui em baixo',
    'lede' => 'Escolhe o formato que o teu banco te deu e larga o ficheiro. Detetamos automaticamente CAMT.053 e MT940.',

    'format_group_aria' => 'Formato do extrato bancário',
    'got_it_as' => 'Obtive-o como:',
    'badge_recommended' => 'recomendado',

    'mini' => [
        'login_label' => 'Inicia sessão',
        'login_sub' => 'No site do teu banco',
        'statements_label' => 'Abre os extratos',
        'statements_sub' => 'No menu do teu banco',
        'range_label' => 'Escolhe um intervalo',
        'range_sub' => 'Últimos 90 dias',
        'download_label' => 'Transfere',
    ],

    'csv_picker_aria' => 'Que banco exportou o teu CSV?',
    'csv_picker_from' => 'De:',

    'drop_lead_camt053' => 'Larga aqui o teu ficheiro CAMT.053',
    'drop_lead_mt940' => 'Larga aqui o teu ficheiro MT940',
    'drop_lead_asn' => 'Larga aqui o teu CSV do ASN',
    'drop_lead_ing' => 'Larga aqui o teu CSV do ING',
    'drop_lead_pick_bank' => 'Escolhe qual o banco que exportou o teu CSV — precisamos de saber para o ler corretamente.',
    'drop_lead_default' => 'Larga aqui o ficheiro do teu extrato',
    'browse_file' => 'ou procura um ficheiro',

    'banks_mt940' => 'Suportados: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Suportados: ASN, ING — mais formatos à medida que os utilizadores contribuem com amostras.',
    'banks_default' => 'Suportados: ASN, ING',

    'file_ready' => '· ✓ pronto',

    'skip' => 'Ignorar este passo',
    'continue' => 'Continuar →',

    'errors' => [
        'file_required' => 'Larga primeiro o ficheiro do teu extrato na caixa.',
        'file_max' => 'Esse ficheiro é demasiado grande. Larga um extrato com menos de 10 MB.',
        'file_extensions' => 'Esse ficheiro não parece um extrato bancário. Larga um ficheiro CAMT.053 XML, CSV ou MT940.',
        'pick_bank' => 'Escolhe qual o banco que exportou o teu CSV antes de continuar.',
        'unreadable' => 'Não foi possível ler este ficheiro. O erro completo está em /dev/logs.',
    ],
];
