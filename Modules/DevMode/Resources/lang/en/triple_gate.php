<?php

declare(strict_types=1);

return [
    'heading' => 'Run a destructive command?',
    'intro' => 'This command will modify your data. Confirm the three locks to continue.',

    'error_dev_mode_off_html' => 'Dev Mode is off (env). Set <code>BEATRAX_DEV_MODE=true</code> + restart to enable destructive runs.',
    'error_advanced_off' => 'Advanced toggle is off. Flip it on in the dev sidebar before running this command.',
    'error_app_name_mismatch' => 'App name did not match. Type the exact lowercase word.',

    'type_to_confirm_html' => 'Type <code>Beatrax</code> to confirm',
    'cancel' => 'Cancel',
    'run' => 'Run :command',
    'running' => 'Running…',
];
