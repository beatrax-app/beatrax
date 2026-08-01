<?php

declare(strict_types=1);

return [
    'heading' => 'System snapshot',

    'subtitle_html' => 'Environment + runtime + effective configuration. Sensitive keys (suffixes <code class="font-mono text-xs">*password*</code>, <code class="font-mono text-xs">*secret*</code>, <code class="font-mono text-xs">*key</code>, <code class="font-mono text-xs">*token*</code>) are masked.',
    'php' => 'PHP',
    'laravel' => 'Laravel',
    'sqlite' => 'SQLite',
    'sqlite_file' => 'file',
    'sqlite_file_size' => 'file size',
    'sqlite_missing' => '(missing)',
    'paths' => 'Paths',
    'environment' => 'Environment',
    'env_empty' => '(no BEATRAX_*, NATIVEPHP_*, or APP_KEY env vars set)',
    'runtime' => 'Runtime',
    'runtime_nativephp' => 'nativephp',
    'runtime_host_os' => 'host os',
    'effective_config' => 'Effective configuration',
    'show_entries' => 'Show :count entries',
    'hide' => 'Hide',
];
