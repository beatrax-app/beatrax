<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Sobre :subject',
        'close' => 'Cerrar',
    ],

    'page_title' => '¿Dónde están mis datos?',
    'intro' => 'Beatrax lo guarda todo en este dispositivo. No hay ningún servidor de Beatrax ni cuenta en la nube. Una sola llamada sale por su cuenta — la comprobación de si hay una versión nueva, que puedes desactivar. Todo lo demás espera a que tú lo pidas: una bandeja de entrada, un banco a través de Enable Banking, una consulta diaria de los tipos de cambio, los dispositivos que emparejas para sincronizar, un relay que configures y cualquier enlace en el que hagas clic. Cada uno lo dice en la pantalla donde lo activas.',

    'lives_here' => 'Tus datos están aquí',
    'copy' => 'Copiar',
    'copied' => 'Copiado',

    'location' => [
        'database' => 'Base de datos:',
        'artefacts_imports' => 'Extractos importados:',
        'artefacts_mail' => 'Correo escaneado:',
        'artefacts_drop' => 'Carpeta vigilada:',
        'backups' => 'Copias de seguridad:',
        'secrets' => 'Credenciales de las conexiones:',
        'logs' => 'Registros:',
    ],

    'copy_aria' => [
        'database' => 'Copiar la ruta de la base de datos al portapapeles',
        'artefacts_imports' => 'Copiar la ruta de los extractos importados al portapapeles',
        'artefacts_mail' => 'Copiar la ruta del correo escaneado al portapapeles',
        'artefacts_drop' => 'Copiar la ruta de la carpeta vigilada al portapapeles',
        'backups' => 'Copiar la ruta de las copias de seguridad al portapapeles',
        'secrets' => 'Copiar la ruta de las credenciales de las conexiones al portapapeles',
        'logs' => 'Copiar la ruta de los registros al portapapeles',
    ],

    'artefacts_heading' => 'Tus documentos originales no están dentro de la copia de seguridad',
    'artefacts_body' => 'Una copia de seguridad contiene la base de datos y nada más. Los extractos que importaste, el correo que recogió el escáner y los recibos que dejaste en la carpeta vigilada siguen donde están, en las tres carpetas de arriba. Guardar una copia de seguridad en un sitio seguro no los copia, así que un archivo completo significa llevarte también esas carpetas — o usar Exportarlo todo aquí abajo, que las empaqueta junto con la copia de seguridad.',

    'export_heading' => 'Exportarlo todo',
    'export_body' => 'Un único archivo con una copia cifrada de tu base de datos y todos los documentos originales que le has dado a Beatrax. Descomprímelo donde quieras y tus documentos estarán dentro tal como siempre estuvieron, en las carpetas de las que salieron.',
    'export_passphrase_label' => 'Frase de contraseña para la base de datos',
    'export_confirm_label' => 'Repite la frase de contraseña',
    'export_passphrase_hint' => 'La base de datos del archivo se cifra con esta frase de contraseña y no hay forma de abrirla sin ella, así que elige algo que vayas a conservar. Tus documentos originales entran tal cual, así que guarda el archivo en un sitio de confianza.',
    'export_cta' => 'Exportarlo todo como ZIP',
    'export_working' => 'Creando el archivo…',

    'delete_heading' => 'Eliminar tus datos',
    'delete_intro' => 'Tus datos son archivos en este dispositivo, así que borrarlos significa borrar esos archivos. Aquí no hay ningún botón que lo haga por ti, y es a propósito: quien guarda de verdad tu historial es el sistema de archivos, y un control que vaciara unas cuantas tablas dejando los archivos en su sitio sería peor que nada.',
    'delete_uninstall' => 'Desinstalar Beatrax no borra tus datos. Es deliberado — una desinstalación accidental no debe destruir años de historial —, así que todo lo de abajo se queda en este dispositivo hasta que lo quites tú.',
    'delete_list_intro' => 'Para no dejar rastro, elimina cada una de estas cosas:',
    'delete_journal_note' => 'Junto a la base de datos hay dos archivos de diario, :wal y :shm. Tus cambios más recientes viven ahí hasta que se integran en la base de datos, así que borra los tres juntos.',
    'no_telemetry' => 'No hay telemetría que desactivar ni cuenta remota que cerrar.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'El tramo de tiempo sobre el que se miden tus presupuestos, tu panel y cualquier cifra de “este periodo”. “:label” decide dónde empieza — ponlo el día después de cobrar y un periodo contiene el dinero que se pagó para cubrirlo. Mover ese día vuelve a archivar cada importe de sobre que ya has asignado, y donde dos periodos antiguos caen sobre uno nuevo sus importes se suman; devolver el día no vuelve a separarlos.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Todo lo que Beatrax sabe que tienes, menos todo lo que debes: el saldo de cada cuenta que has importado o conectado, con los saldos de tarjeta y préstamo contando en contra. No es más completo que lo que le has dado — una cuenta que Beatrax nunca ha visto no está en esta cifra. Los saldos en otra moneda se convierten al tipo que aparece bajo la cifra, y lo que no ha podido valorar se nombra ahí en vez de quedar fuera en silencio.',
];
