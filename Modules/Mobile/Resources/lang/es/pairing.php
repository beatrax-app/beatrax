<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Dispositivo vinculado',
    'page_title' => 'Vincular un dispositivo',

    'scan_heading' => 'Vincula este dispositivo',
    'scan_subtitle' => 'Apunta con la cámara al código que se muestra en el otro dispositivo.',
    'camera_permission_pending' => 'El acceso a la cámara está desactivado. Actívalo para Beatrax en los ajustes de tu dispositivo e inténtalo de nuevo.',
    'open_camera' => 'Abrir la cámara',
    'opening_camera' => 'Esperando el acceso a la cámara…',
    'close_camera' => 'Cerrar la cámara',
    'viewfinder_aria' => 'Visor de la cámara — apúntalo al código de tu otro dispositivo',
    'viewfinder_idle' => 'La cámara está apagada. Ábrela para escanear el código que se muestra en tu otro dispositivo.',
    'scan_prompt' => 'Escanea el código de tu otro dispositivo',
    'enter_code_instead' => 'Introducir el código en su lugar',

    'enter_heading' => 'Introduce el código',
    'camera_off' => 'El acceso a la cámara está desactivado. Introduce el código del otro dispositivo en su lugar.',
    'camera_off_no_search' => 'El acceso a la cámara está desactivado y buscar el otro dispositivo en la red todavía no funciona en el iPhone, así que un código escrito no puede encontrarlo por sí solo. Vuelve a activar el acceso a la cámara para Beatrax en los ajustes del dispositivo y escanea el código del otro dispositivo, o envía el código aquí y esta pantalla te preguntará dónde está.',
    'no_search' => 'Buscar el otro dispositivo en la red todavía no funciona en el iPhone, así que un código escrito no puede encontrarlo por sí solo. Escanea el código con la cámara — no necesita ninguna búsqueda en la red. Si no puedes escanear, envía el código y esta pantalla te preguntará dónde está el otro dispositivo.',
    'word_code_aria' => 'Introduce el código de palabras del otro dispositivo',
    'initiator_address' => '¿Dónde está el otro dispositivo?',
    'initiator_address_help' => 'Su dirección en esta red, como host y puerto. El escritorio la muestra en Dispositivos y sincronización. Vuelve a enviar el código cuando la hayas introducido.',
    'submit_code' => 'Enviar el código',
    'cancel' => 'Cancelar',
    'skip_import' => 'Continuar sin importar',

    'confirm_heading' => 'Compara estas palabras con el otro dispositivo',
    'safety_words_aria' => 'Palabras del número de seguridad: :words',
    'confirm_body' => 'Los dos dispositivos deben mostrar exactamente las mismas palabras. Si no coinciden, toca Cancelar — puede haber un ataque de intermediario en curso.',
    'awaiting_peer' => 'Esperando a que el otro dispositivo confirme...',
    'confirm_match' => 'Confirmar — coinciden',

    'success_heading' => 'Dispositivo vinculado',
    'success_body' => 'Este dispositivo ya es de confianza. Tus datos se sincronizarán en cuanto te conectes.',
    'encryption_incomplete' => 'El dispositivo está emparejado, pero el cifrado de los datos guardados en él no se completó. Todavía no se guardan cifrados.',
    'done' => 'Hecho',

    'errors' => [
        'relay_unreachable' => 'No se puede contactar con el otro dispositivo. Asegúrate de que los dos están en la misma red y de que la sincronización está activada en el ordenador.',
        'no_road_home' => 'Este dispositivo no puede buscar en la red y el código que has escaneado no incluye ninguna dirección del otro dispositivo. Pídele que muestre un código nuevo y escanea ese.',
        'invalid_code' => 'Este código no es válido o ha caducado. Pide al otro dispositivo que genere uno nuevo.',
        'already_under_way' => 'Este dispositivo ya ha aceptado ese código y está esperando a que el otro confirme. Si no lo hace, pide un código nuevo y usa ese.',
        'vouched_but_refused' => 'El otro dispositivo sigue teniendo ese código, pero este no ha podido aceptarlo. Pídele un código nuevo y usa ese.',
        'code_incomplete' => 'Este código no está completo. Compáralo con el otro dispositivo e introdúcelo entero.',
        'initiator_address_invalid' => 'Esa no es una dirección a la que este dispositivo pueda llamar. Introdúcela como host y puerto, por ejemplo 192.168.1.20:8100.',
        'code_not_accepted' => 'Ningún dispositivo de esta red ha aceptado ese código. Comprueba el código y que el otro dispositivo siga mostrándolo.',
        'no_peer_answered' => 'Nada en esta red ha respondido a ese código. Comprueba que la sincronización está activa en el otro dispositivo, o escanea su código con la cámara — la cámara no necesita buscar en la red.',
        'no_peer_answered_ios' => 'Nada en esta red ha respondido a ese código. Buscar el otro dispositivo en la red todavía no funciona en el iPhone, así que escanea su código con la cámara.',
        'no_peer_answered_camera_off' => 'Nada en esta red ha respondido a ese código. Buscar el otro dispositivo en la red todavía no funciona en el iPhone y el acceso a la cámara está desactivado, así que vuelve a activar el acceso a la cámara para Beatrax en los ajustes de tu dispositivo y escanea el código del otro dispositivo.',
        'rate_limited' => 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.',
        'identity_locked' => 'La identidad de tu dispositivo está bloqueada. Desbloquea la app e inténtalo de nuevo.',
        'identity_needs_lock' => 'Configura primero el bloqueo de la aplicación — protege la identidad de tu dispositivo.',
        'safety_number_changed' => 'El otro dispositivo cambió mientras comparabas. Comprueba de nuevo las palabras de abajo antes de confirmar.',
    ],
];
