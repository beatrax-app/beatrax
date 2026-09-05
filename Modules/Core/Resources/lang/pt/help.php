<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Sobre :subject',
        'close' => 'Fechar',
    ],

    'page_title' => 'Onde estão os meus dados?',
    'intro' => 'O Beatrax guarda tudo neste dispositivo. Nada é enviado para um servidor, nada é sincronizado com a nuvem, nada sai deste dispositivo sem que sejas tu a exportá-lo.',

    'lives_here' => 'Os teus dados estão aqui',
    'copy' => 'Copiar',
    'copied' => 'Copiado',

    'location' => [
        'database' => 'Base de dados:',
        'artefacts_imports' => 'Extratos importados:',
        'artefacts_mail' => 'Correio analisado:',
        'artefacts_drop' => 'Pasta vigiada:',
        'backups' => 'Cópias de segurança:',
        'secrets' => 'Credenciais das ligações:',
        'logs' => 'Registos:',
    ],

    'copy_aria' => [
        'database' => 'Copiar o caminho da base de dados para a área de transferência',
        'artefacts_imports' => 'Copiar o caminho dos extratos importados para a área de transferência',
        'artefacts_mail' => 'Copiar o caminho do correio analisado para a área de transferência',
        'artefacts_drop' => 'Copiar o caminho da pasta vigiada para a área de transferência',
        'backups' => 'Copiar o caminho das cópias de segurança para a área de transferência',
        'secrets' => 'Copiar o caminho das credenciais das ligações para a área de transferência',
        'logs' => 'Copiar o caminho dos registos para a área de transferência',
    ],

    'artefacts_heading' => 'Os teus documentos de origem não estão dentro da cópia de segurança',
    'artefacts_body' => 'Uma cópia de segurança contém a base de dados e mais nada. Os extratos que importaste, o correio que o analisador trouxe e os recibos que largaste na pasta vigiada ficam onde estão, nas três pastas indicadas acima. Guardar uma cópia de segurança num sítio seguro não os copia, por isso um arquivo completo implica levar também essas pastas — ou usar Exportar tudo aqui em baixo, que as junta à cópia de segurança por ti.',

    'export_heading' => 'Exportar tudo',
    'export_body' => 'Um único arquivo com uma cópia cifrada da tua base de dados e todos os documentos de origem que deste ao Beatrax. Descompacta-o onde quiseres e os teus documentos estão lá dentro como sempre estiveram, nas pastas de onde vieram.',
    'export_passphrase_label' => 'Frase-passe para a base de dados',
    'export_confirm_label' => 'Repete a frase-passe',
    'export_passphrase_hint' => 'A base de dados dentro do arquivo é cifrada com esta frase-passe e não há forma de a abrir sem ela, por isso escolhe algo que ainda tenhas mais tarde. Os teus documentos de origem entram tal como estão, por isso guarda o arquivo num sítio em que confies.',
    'export_cta' => 'Exportar tudo como ZIP',
    'export_working' => 'A criar o arquivo…',

    'delete_heading' => 'Eliminar os teus dados',
    'delete_intro' => 'Os teus dados são ficheiros neste dispositivo, por isso eliminá-los significa eliminar esses ficheiros. Não há aqui nenhum botão que o faça por ti, e isso é de propósito: quem guarda realmente o teu histórico é o sistema de ficheiros, e um controlo que esvaziasse umas tabelas deixando os ficheiros no lugar seria pior do que nada.',
    'delete_uninstall' => 'Desinstalar o Beatrax não elimina os teus dados. É deliberado — uma desinstalação acidental não pode destruir anos de histórico — por isso tudo o que se segue fica neste dispositivo até seres tu a removê-lo.',
    'delete_list_intro' => 'Para não deixar rasto, elimina cada uma destas coisas:',
    'delete_journal_note' => 'A base de dados tem dois ficheiros de diário ao lado, :wal e :shm. As tuas alterações mais recentes ficam neles até serem integradas na base de dados, por isso elimina os três em conjunto.',
    'no_telemetry' => 'Não há telemetria para desativar nem conta remota para fechar.',
];
