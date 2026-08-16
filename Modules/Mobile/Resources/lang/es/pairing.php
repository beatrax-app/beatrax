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
    'word_code_aria' => 'Introduce el código de palabras del otro dispositivo',
    'submit_code' => 'Enviar el código',
    'cancel' => 'Cancelar',

    'confirm_heading' => 'Compara estas palabras con el otro dispositivo',
    'safety_words_aria' => 'Palabras del número de seguridad: :words',
    'confirm_body' => 'Los dos dispositivos deben mostrar exactamente las mismas palabras. Si no coinciden, toca Cancelar — puede haber un ataque de intermediario en curso.',
    'awaiting_peer' => 'Esperando a que el otro dispositivo confirme...',
    'confirm_match' => 'Confirmar — coinciden',

    'success_heading' => 'Dispositivo vinculado',
    'success_body' => 'Este dispositivo ya es de confianza. Tus datos se sincronizarán en cuanto te conectes.',
    'done' => 'Hecho',

    'errors' => [
        'relay_unreachable' => 'No se puede contactar con el otro dispositivo. Asegúrate de que los dos están en la misma red y de que la sincronización está activada en el ordenador.',
        'import_needs_qr' => 'Escanea el código QR que se muestra en el otro dispositivo para importar.',
        'invalid_code' => 'Este código no es válido o ha caducado. Pide al otro dispositivo que genere uno nuevo.',
        'identity_locked' => 'La identidad de tu dispositivo está bloqueada. Desbloquea la app e inténtalo de nuevo.',
    ],
];
