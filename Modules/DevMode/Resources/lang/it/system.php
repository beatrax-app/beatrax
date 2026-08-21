<?php

declare(strict_types=1);

return [
    'heading' => 'Snapshot del sistema',

    'subtitle_html' => 'Ambiente + runtime + configurazione effettiva. Le chiavi sensibili (suffissi <code class="font-mono text-xs">*password*</code>, <code class="font-mono text-xs">*secret*</code>, <code class="font-mono text-xs">*key</code>, <code class="font-mono text-xs">*token*</code>) sono mascherate.',
    'php' => 'PHP',
    'php_version' => 'versione',
    'php_sapi' => 'sapi',
    'php_ini_path' => 'percorso ini',
    'php_extensions' => 'estensioni',
    'laravel' => 'Laravel',
    'sqlite' => 'SQLite',
    'sqlite_file' => 'file',
    'sqlite_file_size' => 'dimensione del file',
    'sqlite_missing' => '(mancante)',
    'paths' => 'Percorsi',
    'environment' => 'Ambiente',
    'env_empty' => '(nessuna variabile di ambiente BEATRAX_*, NATIVEPHP_* o APP_KEY impostata)',
    'runtime' => 'Runtime',
    'runtime_nativephp' => 'nativephp',
    'runtime_host_os' => 'host os',
    'effective_config' => 'Configurazione effettiva',
    'show_entries' => 'Mostra :count voci',
    'hide' => 'Nascondi',
];
