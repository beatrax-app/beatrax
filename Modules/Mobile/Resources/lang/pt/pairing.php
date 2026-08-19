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
    'word_code_aria' => 'Introduz o código de palavras do outro dispositivo',
    'submit_code' => 'Enviar código',
    'cancel' => 'Cancelar',

    'confirm_heading' => 'Compara estas palavras com o outro dispositivo',
    'safety_words_aria' => 'Palavras do número de segurança: :words',
    'confirm_body' => 'Os dois dispositivos têm de mostrar exatamente as mesmas palavras. Se forem diferentes, toca em Cancelar — pode estar em curso um ataque man-in-the-middle.',
    'awaiting_peer' => 'A aguardar a confirmação do outro dispositivo...',
    'confirm_match' => 'Confirmar — coincidem',

    'success_heading' => 'Dispositivo emparelhado',
    'success_body' => 'Este dispositivo passa a ser de confiança. Os teus dados sincronizam assim que ligares.',
    'done' => 'Concluído',

    'errors' => [
        'relay_unreachable' => 'Não é possível alcançar o outro dispositivo. Verifica se ambos estão na mesma rede e se a sincronização está ativada no computador.',
        'invalid_code' => 'Este código é inválido ou expirou. Pede ao outro dispositivo que gere um novo.',
        'identity_locked' => 'A identidade do teu dispositivo está bloqueada. Desbloqueia a app e tenta novamente.',
    ],
];
