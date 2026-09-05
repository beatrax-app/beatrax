<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Visualización',
        'money' => 'Dinero',
        'insights' => 'Análisis y alertas',
        'security' => 'Seguridad y dispositivos',
        'data' => 'Importaciones y datos',
        'app' => 'App',
    ],

    'title' => 'Ajustes',
    'subtitle' => 'Preferencias sobre cómo se muestran tus finanzas en la app.',

    'appearance' => [
        'heading' => 'Apariencia',
        'theme' => 'Tema',
        'theme_light' => 'Claro',
        'theme_dark' => 'Oscuro',
        'theme_system' => 'Sistema',
        'theme_help' => '«Sistema» sigue el ajuste claro u oscuro de tu sistema operativo.',
    ],

    'language' => [
        'apply' => 'Aplicar',
        'heading' => 'Idioma',
        'label' => 'Idioma de la interfaz',

        'system' => 'Sistema',
        'help' => 'Cambia las palabras que ves en pantalla y cómo se escriben los importes. «Sistema» sigue el idioma de tu navegador o de tu sistema operativo, con el inglés por defecto.',
    ],

    'country' => [
        'heading' => 'País',
        'label' => 'Tu país',
        'help' => 'Determina de qué país son las reglas fiscales, los organismos públicos y las comisiones bancarias que reconoce la app. No cambia el idioma ni cómo se escriben los importes.',
        'choose' => 'Elige un país…',
        'switch_note' => 'Cambiar de país añade categorías nuevas — las etiquetas existentes nunca se modifican.',

        'wording_note' => 'Los nombres de las categorías fiscales aparecen en tu idioma; la declaración de :country usa sus propios términos.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Bélgica',
            'bg' => 'Bulgaria',
            'ca' => 'Canadá',
            'ch' => 'Suiza',
            'cy' => 'Chipre',
            'cz' => 'Chequia',
            'de' => 'Alemania',
            'dk' => 'Dinamarca',
            'ee' => 'Estonia',
            'es' => 'España',
            'fi' => 'Finlandia',
            'fr' => 'Francia',
            'gb' => 'Reino Unido',
            'gr' => 'Grecia',
            'hr' => 'Croacia',
            'hu' => 'Hungría',
            'ie' => 'Irlanda',
            'is' => 'Islandia',
            'it' => 'Italia',
            'lt' => 'Lituania',
            'lu' => 'Luxemburgo',
            'lv' => 'Letonia',
            'mt' => 'Malta',
            'nl' => 'Países Bajos',
            'no' => 'Noruega',
            'pl' => 'Polonia',
            'pt' => 'Portugal',
            'ro' => 'Rumanía',
            'se' => 'Suecia',
            'si' => 'Eslovenia',
            'sk' => 'Eslovaquia',
            'us' => 'Estados Unidos',
        ],
    ],

    'currency_display' => [
        'heading' => 'Visualización del importe',
        'label' => 'Vista por defecto de los importes',
        'eur_only' => 'Importe liquidado',
        'original' => 'Importe original',
        'help' => 'Se aplica a la lista de transacciones y a los totales del panel. Puedes cambiarlo página a página, pero solo desde la lista de transacciones.',
    ],

    'base_currency' => [
        'heading' => 'Moneda base de los informes',
        'label' => 'Moneda de los informes',
        'help' => 'Todos los totales y resúmenes se convierten a esta moneda. Cada cuenta sigue mostrando al lado su propia moneda original.',
    ],

    'exchange_rates' => [
        'heading' => 'Tipos de cambio',
        'fetch_online' => 'Obtener los tipos actuales en línea',
        'online_on' => 'Los tipos se obtienen a diario del BCE, o de Frankfurter si el BCE no está disponible. Solo consultas de pares de monedas — ningún dato personal.',
        'last_updated' => 'Última actualización: :date.',
        'online_off' => 'Se siguen usando los tipos que ya hay en este dispositivo, con la instantánea incluida como respaldo. Ningún dato sale de este dispositivo.',
        'fetch_aria' => 'Obtener los tipos de cambio actuales en línea',
        'refreshing' => 'Actualizando…',
        'next_refresh' => 'Actualización automática: una vez al día',
        'refresh_gave_up' => 'No se han podido actualizar los tipos. Se siguen usando los que ya hay en este dispositivo.',
        'refresh_now' => 'Actualizar ahora',
    ],

    'period' => [
        'heading' => 'Periodo',
        'label' => 'El periodo empieza el día',
        'help' => 'Numerado del 1 al 28. La mayoría lo deja en 1 (mes natural). Usa 25 si tu nómina llega el día 25 y para ti «tu mes» empieza entonces.',

        'move_confirm' => 'Si el periodo empieza el día :day, todos los importes de los sobres se vuelven a archivar y se suman de dos en dos allí donde dos meses se funden en uno. Volver a cambiar el día no los separa de nuevo.',
        'move_cancel' => 'Cancelar',
        'move_apply' => 'Aplicar',
    ],

    'recurring' => [
        'heading' => 'Detección de recurrentes',
        'window_label' => 'Ventana de detección (meses)',
        'window_help' => 'Cuántos meses de historial se analizan al agrupar transacciones en patrones recurrentes.',
        'income_label' => 'Ingreso mínimo (unidades menores)',
        'income_help' => 'Los ingresos por debajo de este umbral no se agrupan automáticamente. Se guarda en unidades menores — :minor significa :example. Ponlo a 0 para desactivar el umbral.',
    ],

    'drift' => [
        'heading' => 'Alertas de desviación',
        'label' => 'Umbral por defecto de las alertas de desviación',
        'help' => 'Las alertas saltan cuando el último importe de un cargo recurrente se desvía del importe anterior más que este porcentaje. Los ajustes por serie tienen prioridad.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (por defecto)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Guardar ajustes',
    'saved' => 'Guardado.',

    'anomaly_heading' => 'Detección de anomalías',
    'notifications_heading' => 'Notificaciones',

    'forecasting' => [
        'heading' => 'Previsiones',
        'intro' => 'Beatrax proyecta tu saldo a partir del estado actual de tus cuentas. Para las cuentas sin saldo de extracto (PayPal, importaciones CSV antiguas), indica aquí el saldo inicial para que las proyecciones partan de un punto conocido.',
        'no_accounts' => 'Aún no hay cuentas — importa un extracto para añadir una.',
    ],

    'auto_import' => [
        'heading' => 'Importación automática',
        'label' => 'Importar automáticamente desde la carpeta de entrega',

        'active_html' => 'La carpeta de entrega está activa. Beatrax analiza <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> cada 5 minutos en busca de archivos nuevos.',
        'inactive_html' => 'Cuando está activada, Beatrax analiza <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> cada 5 minutos en busca de archivos <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> y <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> y los importa por el mismo proceso de emparejado que el asistente. Los archivos procesados se mueven a <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> para que nunca se importen dos veces.',
        'active_phone_html' => 'La carpeta de entrega está activa. Beatrax analiza <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> en segundo plano en busca de archivos nuevos. Tu teléfono decide cuándo se ejecuta un análisis en segundo plano, así que pueden pasar minutos u horas.',
        'inactive_phone_html' => 'Cuando está activada, Beatrax analiza <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> en segundo plano en busca de archivos <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> y <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> y los importa por el mismo proceso de emparejado que el asistente. Tu teléfono decide cuándo se ejecuta un análisis en segundo plano, así que pueden pasar minutos u horas. Los archivos procesados se mueven a <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> para que nunca se importen dos veces.',
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Revisa y edita los nombres legibles que le has enseñado a Beatrax para las descripciones crípticas de los extractos.',
        'manage' => 'Gestionar alias →',
    ],

    'tax_heading' => 'Impuestos',
    'data_backup_heading' => 'Datos y copia de seguridad',

    'about_updates' => [
        'heading' => 'Sobre las actualizaciones',
        'body' => 'Beatrax se actualiza solo una vez instalado. Después de instalar la primera versión, las siguientes llegan mediante un aviso dentro de la app — no necesitas volver a GitHub. Si alguna actualización futura no llega a aplicarse, siempre puedes volver a descargar el último instalador a mano desde la página de versiones.',
        'body_phone' => 'Aquí Beatrax no se actualiza solo. Las nuevas versiones de la app para el móvil llegan por la App Store o Google Play, igual que el resto de tus apps.',
        'check_label' => 'Buscar actualizaciones automáticamente',
        'check_on' => 'Beatrax pregunta al canal de versiones si existe una versión firmada más reciente. No se descarga nada hasta que tú elijas instalarla.',
        'check_off' => 'No se comprueba si hay actualizaciones y nada sale de este dispositivo. Las nuevas versiones se encuentran abriendo tú mismo la página de versiones.',
        'open_releases' => 'Abrir la página de versiones →',
    ],

    'privacy' => [
        'heading' => 'Política de privacidad',
        'body' => 'Beatrax guarda tus finanzas en tus propios dispositivos. La política explica qué significa eso, qué envían las funciones en línea opcionales y cómo eliminar tus datos.',
        'open' => 'Leer la política de privacidad →',
        'url_hint' => 'Si el enlace no se abre, visita:',
    ],

    'first_run_tour' => [
        'heading' => 'Recorrido inicial',
        'body' => 'Vuelve a lanzar el asistente de configuración si quieres repasar el recorrido de introducción.',
        'run_again' => 'Volver a lanzar el asistente de configuración',
    ],

    'developer' => [
        'heading' => 'Desarrollador',
        'label' => 'Dev Console integrada',
        'help' => 'Muestra la Dev Console en /dev. Restablece la opción Avanzado en cada inicio de sesión.',
        'aria' => 'Modo desarrollador',
    ],

    'errors' => [
        'period_move_failed' => 'No se pudo mover el mes de presupuesto, así que se quedó donde estaba.',
        'currency_required' => 'Elige una moneda.',
        'window_months' => 'Elige entre 2 y 60 meses.',
        'threshold' => 'Elige un umbral entre 1 %, 2 %, 5 %, 10 %, 25 % o 50 %.',
        'amount' => 'Introduce un importe a partir de :zero.',
        'period_day' => 'Elige un día del 1 al 28.',
        'currency_view' => 'Elige una de las opciones disponibles.',
    ],
];
