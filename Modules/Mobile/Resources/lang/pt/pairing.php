<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Dispositivo emparelhado',
    'page_title' => 'Emparelhar um dispositivo',

    'scan_heading' => 'Emparelhar este dispositivo',
    'scan_subtitle' => 'Aponta a câmara para o código mostrado no outro dispositivo.',
    'camera_permission_pending' => 'O acesso à câmara está desativado. Autoriza-o para o Beatrax nas definições do teu dispositivo e tenta novamente.',
    'open_camera' => 'Abrir a câmara',
    'opening_camera' => 'A aguardar o acesso à câmara…',
    'close_camera' => 'Fechar a câmara',
    'viewfinder_aria' => 'Visor da câmara — aponta-o para o código no teu outro dispositivo',
    'viewfinder_idle' => 'A câmara está desligada. Abre-a para ler o código mostrado no teu outro dispositivo.',
    'scan_prompt' => 'Lê o código no teu outro dispositivo',
    'enter_code_instead' => 'Introduzir o código',

    'enter_heading' => 'Introduz o código',
    'camera_off' => 'O acesso à câmara está desativado. Introduz antes o código do outro dispositivo.',
    'camera_off_no_search' => 'O acesso à câmara está desativado e procurar o outro dispositivo na rede ainda não funciona no iPhone — um código escrito não tem, assim, como encontrá-lo. Volta a autorizar a câmara para o Beatrax nas definições do teu dispositivo e digitaliza o código do outro dispositivo.',
    'no_search' => 'Procurar o outro dispositivo na rede ainda não funciona no iPhone, por isso um código escrito não tem nada para encontrar. Digitaliza antes o código com a câmara — a câmara não precisa de procurar na rede.',
    'word_code_aria' => 'Introduz o código de palavras do outro dispositivo',
    'submit_code' => 'Enviar código',
    'cancel' => 'Cancelar',
    'skip_import' => 'Continuar sem importar',

    'confirm_heading' => 'Compara estas palavras com o outro dispositivo',
    'safety_words_aria' => 'Palavras do número de segurança: :words',
    'confirm_body' => 'Os dois dispositivos têm de mostrar exatamente as mesmas palavras. Se forem diferentes, toca em Cancelar — pode estar em curso um ataque man-in-the-middle.',
    'awaiting_peer' => 'A aguardar a confirmação do outro dispositivo...',
    'confirm_match' => 'Confirmar — coincidem',

    'success_heading' => 'Dispositivo emparelhado',
    'success_body' => 'Este dispositivo passa a ser de confiança. Os teus dados sincronizam assim que ligares.',
    'encryption_incomplete' => 'O dispositivo está emparelhado, mas a cifragem dos dados guardados nele não foi concluída. Os dados ainda não são guardados cifrados.',
    'done' => 'Concluído',

    'errors' => [
        'relay_unreachable' => 'Não é possível alcançar o outro dispositivo. Verifica se ambos estão na mesma rede e se a sincronização está ativada no computador.',
        'no_road_home' => 'Este dispositivo não consegue procurar na rede e o código que digitalizaste não inclui qualquer endereço do outro dispositivo. Pede-lhe um código novo e digitaliza esse.',
        'invalid_code' => 'Este código é inválido ou expirou. Pede ao outro dispositivo que gere um novo.',
        'code_incomplete' => 'Este código não está completo. Compara-o com o outro dispositivo e introdu-lo por inteiro.',
        'code_not_accepted' => 'Nenhum dispositivo nesta rede aceitou esse código. Verifica o código e se o outro dispositivo ainda o está a mostrar.',
        'no_peer_answered' => 'Nada nesta rede respondeu a esse código. Verifica se a sincronização está a correr no outro dispositivo, ou digitaliza o código dele com a câmara — a câmara não precisa de procurar na rede.',
        'no_peer_answered_ios' => 'Nada nesta rede respondeu a esse código. Procurar o outro dispositivo na rede ainda não funciona no iPhone, por isso digitaliza o código dele com a câmara.',
        'no_peer_answered_camera_off' => 'Nada nesta rede respondeu a esse código. Procurar o outro dispositivo na rede ainda não funciona no iPhone e o acesso à câmara está desativado — volta por isso a autorizar a câmara para o Beatrax nas definições do teu dispositivo e digitaliza o código do outro dispositivo.',
        'rate_limited' => 'Demasiadas tentativas. Espera um minuto e tenta novamente.',
        'identity_locked' => 'A identidade do teu dispositivo está bloqueada. Desbloqueia a app e tenta novamente.',
        'identity_needs_lock' => 'Configure primeiro o bloqueio da aplicação — é ele que protege a identidade do seu dispositivo.',
        'safety_number_changed' => 'O outro dispositivo mudou enquanto comparavas. Verifica novamente as palavras abaixo antes de confirmar.',
    ],
];
