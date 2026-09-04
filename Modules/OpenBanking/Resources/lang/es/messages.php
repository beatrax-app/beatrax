<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Ajustes',
        'heading' => 'Open banking',
        'subtitle' => 'Descarga automáticamente las transacciones de ASN o SNS a través de Enable Banking, un agregador PSD2 externo. Desactivado por defecto.',
        'toggle_label' => 'Activar el open banking',
        'toggle_connected' => 'Conectado a :bank a través de Enable Banking.',
        'toggle_off_help' => 'Desactivado por defecto. Requiere una aceptación única y una configuración guiada.',
        'credentials_unreadable' => 'No se pueden leer las credenciales de open banking guardadas en este dispositivo, así que Beatrax no puede conectarse con tu banco.',
        'credentials_unreadable_next' => 'Vuelve a hacer la configuración guiada para reemplazarlas. Las transacciones ya importadas no se ven afectadas.',
        'reconfirm_body' => 'Tu aceptación caducó antes de que pudiéramos terminar la conexión. Vuelve a confirmarla para terminar de activar el open banking.',
        'reconfirm_button' => 'Vuelve a confirmar para terminar',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Gestionar el open banking',
        'not_connected' => 'Ningún banco conectado. Conecta uno para importar transacciones automáticamente.',
        'expired' => 'Consentimiento caducado — hay que volver a conectar.',
        'revoked' => 'Tu banco ha finalizado la conexión: vuelve a conectar.',
        'connected' => 'Conectado a :bank a través de Enable Banking. Última sincronización :when.',
        'never' => 'nunca',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregador',
        'bank_label' => 'Banco',
        'consent_status_label' => 'Estado del consentimiento',
        'pill_expired' => 'Caducado — vuelve a conectar',
        'pill_expiring' => 'Caduca pronto',
        'pill_connected' => 'Conectado',
        'pill_revoked' => 'Finalizada por tu banco: vuelve a conectar',
        'whats_fetched_label' => 'Qué se descarga',
        'whats_fetched' => 'Transacciones contabilizadas y saldos, últimos 90 días',
        'last_successful_sync_label' => 'Última sincronización correcta',
        'never' => 'Nunca',
        'last_attempt_label' => 'Último intento',
        'last_attempt_failed' => ':when — fallido (:reason)',
        'reason_consent_expired' => 'consentimiento caducado',
        'reason_error' => 'error',
        'reason_truncated' => 'detenida antes de tiempo',
        'reason_nothing_imported' => 'no se pudo registrar nada',
        'reason_consent_revoked' => 'finalizada por tu banco',
        'disconnect_button' => 'Desconectar',
    ],

    'consent_banner' => [
        'heading' => 'Consentimiento caducado — vuelve a conectar',
        'heading_revoked' => 'Tu banco ha finalizado la conexión',
        'body' => 'Tu última sincronización correcta fue :when. Vuelve a conectar para reanudar la sincronización automática.',
        'body_revoked' => 'Tu banco o Enable Banking ha retirado el acceso, así que la sincronización se ha detenido. Tu última sincronización correcta fue :when. Vuelve a conectar para reanudarla.',
        'never' => 'nunca',
        'reconnect' => 'Volver a conectar',
    ],

    'sync' => [
        'review_import' => 'Revisar la importación',
        'reconnect_first' => 'Vuelve a conectar primero',
        'auto_caption' => 'Se sincroniza automáticamente una vez al día.',
        'sync_now' => 'Sincronizar ahora',

        'consent_expired' => 'Consentimiento caducado — vuelve a conectar.',
        'unavailable' => 'Enable Banking no está disponible temporalmente. Inténtalo de nuevo en un momento.',
        'new_found' => 'Se ha encontrado :count transacción nueva.|Se han encontrado :count transacciones nuevas.',
        'none' => 'No hay transacciones nuevas.',
        'none_importable' => 'Tu banco ha enviado transacciones, pero no se ha podido registrar ninguna. Abre la revisión de la importación para ver por qué.',
        'in_progress' => 'Ya hay una sincronización en curso. Inténtalo de nuevo en un momento.',
        'truncated' => 'Tu banco tenía más transacciones de las que una sincronización puede recuperar, así que esta ejecución se detuvo antes de tiempo. No se ha registrado nada como sincronizado: la próxima sincronización empezará en el mismo punto.',
    ],

    'disconnect' => [
        'heading' => '¿Desconectar el open banking?',
        'body' => 'Esto elimina las credenciales y el consentimiento de Enable Banking que tienes guardados. La sincronización automática se detiene de inmediato. Las transacciones ya importadas en Beatrax no se ven afectadas.',
        'confirm' => 'Desconectar',
        'cancel' => 'Seguir conectado',
    ],

    'ics' => [
        'section_label' => 'Importación de archivo — no se guarda ninguna credencial',
        'heading' => 'Extracto de tarjeta de crédito ICS',
        'step_login' => 'Inicia sesión',
        'step_download' => 'Descarga el extracto',
        'pdf_statement' => 'Extracto en PDF',
        'step_drop' => 'Suéltalo aquí abajo',
        'drop_zone_label' => 'Suelta aquí el archivo de tu extracto',
        'drop_zone_hint' => 'o busca un archivo',
        'browse_aria' => 'Buscar un archivo de extracto de ICS',
        'import_button' => 'Importar el extracto',
        'validation' => [
            'required' => 'Suelta el extracto de ICS que has descargado de Mijn ICS.',
            'max' => 'Ese archivo es demasiado grande. Los extractos de ICS en PDF suelen ocupar menos de 1 MB cada uno.',
            'extensions' => 'Eso no es un PDF. Mijn ICS solo exporta extractos en PDF.',
        ],
        'could_not_read' => 'No se ha podido leer :filename. El error completo está en /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Antes de conectar con un tercero',
        'body' => 'Activar el open banking envía tu consentimiento de acceso bancario y, después, tus datos de transacciones y saldos directamente desde este dispositivo a Enable Banking y a tu banco. Beatrax no tiene ningún servidor que vea estos datos, pero Enable Banking y tu banco sí. Esto es distinto de cualquier otro método de importación de Beatrax, que nunca envía datos a ninguna parte.',
        'acknowledge' => 'Entiendo que mis datos de transacciones se compartirán con Enable Banking y con mi banco.',
        'confirm' => 'Activar el open banking',
        'cancel' => 'Cancelar',
    ],

    'wizard' => [
        'heading' => 'Conecta tu banco',
        'intro' => 'Beatrax usa tu propia aplicación de Enable Banking para que tus credenciales nunca pasen por un servidor compartido. Es una configuración única por banco.',

        'step1_title' => 'Genera tu par de claves local',
        'step1_body' => 'Beatrax genera un par de claves RSA en este dispositivo. La clave privada nunca sale de él.',
        'generate_keypair' => 'Generar par de claves',
        'public_key_label' => 'Clave pública',
        'copy_public_key' => 'Copiar la clave pública',
        'copied' => 'Copiada',
        'redirect_uri_label' => 'URI de redirección',
        'copy_redirect_uri' => 'Copiar la URI de redirección',

        'step2_title' => 'Registra la aplicación en Enable Banking',
        'step2_body' => 'Abre el portal para desarrolladores de Enable Banking, crea una aplicación y pega la clave pública y la URI de redirección del paso 1.',
        'open_portal' => 'Abrir el portal de Enable Banking ↗',

        'step3_title' => 'Pega tu ID de aplicación',
        'application_id_label' => 'ID de aplicación',
        'step3_help' => 'Se guarda en un archivo local fuera de la base de datos, con permisos restrictivos, y nunca sale de este dispositivo.',

        'step4_title' => 'Elige tu banco',
        'via_enable_banking' => 'a través de Enable Banking',
        'other_institution' => 'Otra entidad',
        'institution_id_placeholder' => 'ID de la entidad',

        'step5_title' => 'Completa el consentimiento en tu navegador',
        'step5_body' => 'Haz clic abajo para abrir la pantalla de acceso y consentimiento de tu banco. Completa el acceso y cualquier paso de doble factor y volverás aquí automáticamente para terminar de activar el Open Banking.',
        // i18n-review: es · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Toca abajo para abrir la pantalla de acceso y consentimiento de tu banco. Completa el acceso y cualquier paso de doble factor y volverás aquí automáticamente para terminar de activar el Open Banking.',

        'cancel' => 'Cancelar',
        'continue' => 'Continuar →',
        'continue_to_bank' => 'Continuar a :bank →',
        'your_bank' => 'tu banco',

        'errors' => [
            'save_keypair_failed' => 'No se ha podido guardar tu par de claves en disco — revisa los permisos del directorio de secretos e inténtalo de nuevo.',
            'generate_failed' => 'No se ha podido generar un par de claves en este dispositivo — revisa tu configuración de OpenSSL.',
            'export_failed' => 'No se ha podido exportar el par de claves generado.',
            'read_public_failed' => 'No se ha podido leer la clave pública generada.',
            'generate_first' => 'Genera un par de claves antes de continuar.',
            'paste_application_id' => 'Pega el ID de aplicación del portal de Enable Banking antes de continuar.',
            'save_application_id_failed' => 'No se ha podido guardar tu ID de aplicación en disco — revisa los permisos del directorio de secretos e inténtalo de nuevo.',
            'choose_bank' => 'Elige un banco antes de continuar.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Termina primero el asistente de configuración de Open Banking.',
        'no_bank_chosen' => 'Elige un banco antes de conectar.',
        'no_consent_url' => 'Enable Banking no ha devuelto ninguna URL de consentimiento.',
        'unparseable_consent_url' => 'Enable Banking ha devuelto una URL de consentimiento que no se puede analizar.',
        'non_public_consent_host' => 'Enable Banking ha devuelto un host de consentimiento no público.',
        'unsafe_consent_url' => 'Enable Banking ha devuelto una URL de consentimiento no segura.',
        'no_authorization_code' => 'La respuesta de Enable Banking no ha devuelto ningún código de autorización.',
        'no_session_id' => 'Enable Banking no ha devuelto ningún ID de sesión.',
        'oauth_state_mismatch' => 'Ese enlace de conexión ha caducado o ya se ha utilizado. Vuelve a iniciar la conexión con tu banco.',
    ],
];
