<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Configura tu cliente OAuth de Gmail',
    'microsoft_title' => 'Configura tu cliente OAuth de Microsoft 365',
    'intro' => 'Beatrax usa tu propio proyecto de Google Cloud o tu propio registro de aplicación de Azure, para que tus credenciales nunca pasen por un servidor compartido. Es una configuración única por proveedor.',

    'copied' => 'Copiado',
    'cancel' => 'Cancelar',
    'save_connect' => 'Guardar y conectar',

    'secret_help' => 'Se guardan en un archivo de configuración local fuera de la base de datos, con permisos restrictivos, y nunca salen de este dispositivo.',

    'gmail' => [
        'step1_title' => 'Abre la Google Cloud Console',
        'step1_body' => 'Abre la Google Cloud Console en una pestaña nueva. Inicia sesión con la cuenta de Google que quieras analizar y crea un proyecto nuevo (o selecciona un proyecto personal existente).',
        'step1_link' => 'Abrir la Google Cloud Console',
        'step2_title' => 'Activa la API de Gmail',
        'step2_body' => 'En el proyecto nuevo, busca "Gmail API" en la biblioteca de API y pulsa Habilitar. Esto permite que el proyecto llame a Gmail en tu nombre.',
        'step3_title' => 'Configura la pantalla de consentimiento de OAuth',
        'step3_body' => 'Abre API y servicios → Pantalla de consentimiento de OAuth. Elige el tipo de usuario "Externo", pon "Beatrax" como nombre de la aplicación y tu propio correo como contacto de soporte y de desarrollador. Añade el ámbito https://www.googleapis.com/auth/gmail.readonly. Pulsa Guardar y continuar y luego Volver al panel.',
        'step4_title' => 'Pasa la pantalla de consentimiento a "En producción"',
        'step4_body' => 'En la página de la pantalla de consentimiento de OAuth, pulsa Publicar aplicación y confirma. Es imprescindible: sin ello, los tokens de actualización que recibe Beatrax caducan a los 7 días. Publicar no requiere ninguna revisión de Google cuando el único usuario eres tú.',
        'step4_checkbox' => 'He publicado la pantalla de consentimiento de OAuth en producción',
        'step5_title' => 'Crea el ID de cliente de OAuth',
        'step5_body' => 'Abre Credenciales → Crear credenciales → ID de cliente de OAuth. Elige el tipo de aplicación "Aplicación web". Ponle el nombre "Beatrax". En "URI de redireccionamiento autorizados", pega exactamente la URI de abajo.',
        'step6_title' => 'Pega tu ID de cliente y tu secreto de cliente',
        'client_id_label' => 'ID de cliente',
        'client_secret_label' => 'Secreto de cliente',
    ],

    'microsoft' => [
        'step1_title' => 'Abre el portal de Azure',
        'step1_body' => 'Abre el centro de administración de Microsoft Entra en una pestaña nueva. Inicia sesión con la cuenta de Microsoft que quieras analizar.',
        'step1_link' => 'Abrir el portal de Azure',
        'step2_title' => 'Registra una aplicación nueva',
        'step2_body' => 'Abre Registros de aplicaciones → Nuevo registro. Ponle el nombre "Beatrax". En "Tipos de cuenta compatibles", elige "Cuentas en cualquier directorio organizativo y cuentas personales de Microsoft" (así podrás conectar buzones personales de Outlook.com y buzones de trabajo de Microsoft 365 con la misma aplicación).',
        'step3_title' => 'Añade la URI de redireccionamiento',
        'step3_body' => 'En el mismo formulario de registro, en "URI de redireccionamiento", elige la plataforma "Web" y pega exactamente la URI de abajo.',
        'step4_title' => 'Concede el permiso Mail.Read',
        'step4_body' => 'Abre Permisos de API → Agregar un permiso → Microsoft Graph → Permisos delegados. Selecciona Mail.Read y offline_access. Pulsa Agregar permisos. Para una cuenta personal no hace falta el consentimiento del administrador.',
        'step5_title' => 'Crea un secreto de cliente',
        'step5_body' => 'Abre Certificados y secretos → Nuevo secreto de cliente. Pon la descripción "Beatrax" y una caducidad de 24 meses. Copia el valor del secreto de inmediato: Azure solo lo muestra una vez.',
        'step6_title' => 'Pega el ID de aplicación (cliente) y el secreto',
        'client_id_label' => 'ID de aplicación (cliente)',
        'client_secret_label' => 'Valor del secreto de cliente',
    ],

    'errors' => [
        'pick_provider' => 'Elige un proveedor antes de enviar.',
        'microsoft_client_id' => 'Introduce el ID de aplicación (cliente): un UUID como 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Introduce el valor del secreto de cliente que te mostró Azure al crearlo.',
        'google_client_id' => 'Introduce un ID de cliente de OAuth de Google que acabe en .apps.googleusercontent.com.',
        'google_secret' => 'Introduce un secreto de cliente de OAuth de Google que empiece por GOCSPX-.',
        'google_published' => 'Confirma que has pasado tu pantalla de consentimiento de OAuth a "En producción".',
        'write_failed' => 'No se pudo guardar tu cliente OAuth en el disco — revisa los permisos del directorio de secretos e inténtalo de nuevo.',
    ],
];
