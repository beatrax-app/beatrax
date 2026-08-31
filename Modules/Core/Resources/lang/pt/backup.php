<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Esta aplicação não consegue entregar um ficheiro ao teu dispositivo, por isso a cópia cifrada é feita na aplicação de computador. Emparelha este dispositivo para os manter sincronizados.',
        'unavailable' => 'As cópias de segurança encriptadas estão disponíveis na versão para computador (SQLite). Numa base de dados em servidor, usa as ferramentas de cópia de segurança da própria base de dados.',
        'intro' => 'Transfere uma cópia de toda a tua base de dados encriptada com uma frase-passe — segura para guardar num disco externo ou no armazenamento na nuvem, porque é ilegível sem a frase-passe (XChaCha20-Poly1305 + Argon2id, resistentes a computação quântica).',
        'passphrase' => 'Frase-passe',
        'confirm_passphrase' => 'Confirmar a frase-passe',
        'keep_safe' => 'Guarda a frase-passe em segurança — sem ela não há forma de recuperar a cópia de segurança.',
        'submit' => 'Transferir a cópia de segurança encriptada',
        'preparing' => 'A preparar…',
    ],

    'restore' => [
        'heading' => 'Restaurar a partir de uma cópia de segurança',

        'intro_html' => 'Substitui a tua base de dados atual por uma cópia de segurança encriptada. O ficheiro é desencriptado e verificado antes de alguma coisa mudar, e é guardado primeiro um instantâneo dos teus dados atuais — mas isto continua a <strong class="text-slate-700 dark:text-slate-200">substituir tudo</strong>, por isso está protegido. A tua sessão será terminada, porque o teu início de sessão também está na base de dados.',
        'restored' => 'A sua cópia de segurança foi restaurada. Inicie sessão com o nome de utilizador e a palavra-passe em uso quando foi criada.',
        'snapshot_saved_prefix' => 'Foi guardado um instantâneo dos teus dados anteriores em',
        'file_label' => 'Cópia de segurança encriptada (.enc)',
        'uploading' => 'A carregar…',
        'passphrase' => 'Frase-passe',
        'confirm_prefix' => 'Escreve',
        'confirm_suffix' => 'para confirmar',
        'submit' => 'Restaurar (substitui os dados atuais)',
        'restoring' => 'A restaurar…',
    ],

    'errors' => [
        'passphrase_min' => 'Usa uma frase-passe com pelo menos :min carácter.|Usa uma frase-passe com pelo menos :min caracteres.',
        'passphrase_mismatch' => 'As duas frases-passe não coincidem.',
        'download_sqlite_only' => 'A transferência encriptada só está disponível na versão SQLite.',
        'create_failed' => 'Não foi possível criar a cópia de segurança: :message',
        'confirm_phrase' => 'Escreve :phrase para confirmar — isto substitui os teus dados atuais.',
        'choose_file' => 'Escolhe um ficheiro de cópia de segurança encriptada (.enc) para restaurar.',
        'upload_failed' => 'O ficheiro não terminou de ser carregado. Pode ser demasiado grande para este dispositivo — restaurar na aplicação de computador aceita uma cópia maior.',
        'enter_passphrase' => 'Introduz a frase-passe com que a cópia de segurança foi encriptada.',
        'unreadable' => 'Não foi possível ler o ficheiro carregado. Tenta novamente.',
        'restore_wrong_passphrase' => 'Essa frase-passe não abriu esta cópia de segurança e nada foi alterado. Escreve-a de novo e tenta outra vez. Se for mesmo a certa, o ficheiro foi alterado depois de ser criado: restaura outra cópia.',
        'restore_not_a_backup' => 'Este ficheiro não é uma cópia de segurança cifrada do Beatrax, por isso não há nada para restaurar e nada foi alterado. Escolhe o ficheiro .enc que a app escreveu quando fizeste a cópia.',
        'restore_contents_unreadable' => 'A cópia de segurança abriu, mas a base de dados lá dentro está danificada, por isso não foi restaurada e nada foi alterado. Restaura uma cópia mais antiga.',
        'restore_could_not_read' => 'Não foi possível ler o ficheiro da cópia de segurança, por isso o restauro não correu e nada foi alterado. Verifica se este dispositivo tem espaço livre e tenta outra vez.',
        'restore_not_supported' => 'O restauro funciona na versão que guarda os dados num único ficheiro, e esta não é, por isso nada foi alterado. Numa base de dados em servidor, usa as ferramentas de restauro dessa base de dados.',
        'restore_failed' => 'O restauro não correu e nada foi alterado. Tenta outra vez — se continuar a falhar, o registo da app guarda o que o impediu.',
    ],
];
