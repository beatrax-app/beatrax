<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'About :subject',
        'close' => 'Close',
    ],

    'page_title' => 'Where is my data?',
    'intro' => 'Beatrax stores everything on this device. There is no Beatrax server and no cloud account. One call goes out on its own — a check for a new version, which you can turn off. Everything else waits for you: a mailbox, a bank through Enable Banking, a daily exchange-rate lookup, the devices you pair for sync, a relay you configure, and any link you click. Each one says so on the screen where you turn it on.',

    'lives_here' => 'Your data lives here',
    'copy' => 'Copy',
    'copied' => 'Copied',

    'location' => [
        'database' => 'Database:',
        'artefacts_imports' => 'Imported statements:',
        'artefacts_mail' => 'Scanned mail:',
        'artefacts_drop' => 'Watched drop folder:',
        'backups' => 'Backups:',
        'secrets' => 'Connector credentials:',
        'logs' => 'Logs:',
    ],

    'copy_aria' => [
        'database' => 'Copy database path to clipboard',
        'artefacts_imports' => 'Copy imported statements path to clipboard',
        'artefacts_mail' => 'Copy scanned mail path to clipboard',
        'artefacts_drop' => 'Copy watched drop folder path to clipboard',
        'backups' => 'Copy backups path to clipboard',
        'secrets' => 'Copy connector credentials path to clipboard',
        'logs' => 'Copy logs path to clipboard',
    ],

    'artefacts_heading' => 'Your source documents are not inside the backup',
    'artefacts_body' => 'A backup holds the database and nothing else. The statements you imported, the mail the scanner pulled in and the receipts you dropped in the watched folder stay where they are, in the three folders listed above. Copying a backup somewhere safe does not copy them, so a full archive means taking those folders too — or using Export everything below, which bundles them with the backup for you.',

    'export_heading' => 'Export everything',
    'export_body' => 'One archive holding an encrypted copy of your database and every source document you gave Beatrax. Unzip it anywhere and your documents are inside as they always were, in the folders they came from.',
    'export_passphrase_label' => 'Passphrase for the database',
    'export_confirm_label' => 'Repeat the passphrase',
    'export_passphrase_hint' => 'The database inside the archive is encrypted with this passphrase and there is no way to open it without one, so pick something you will still have. Your source documents go in as they are, so keep the archive somewhere you trust.',
    'export_cta' => 'Export everything as ZIP',
    'export_working' => 'Building the archive…',

    'delete_heading' => 'Deleting your data',
    'delete_intro' => 'Your data is files on this device, so deleting it means deleting those files. There is no button here that does it for you, and that is on purpose: the filesystem is what actually holds your history, and a control that emptied a few tables while leaving the files in place would be worse than nothing.',
    'delete_uninstall' => 'Uninstalling Beatrax does not delete your data. That is deliberate — an accidental uninstall must not destroy years of history — so everything below stays on this device until you remove it yourself.',
    'delete_list_intro' => 'To remove every trace, delete each of these:',
    'delete_journal_note' => 'The database keeps two journal files beside it, :wal and :shm. Your most recent changes live in those until they are folded into the database, so delete all three together.',
    'no_telemetry' => "There's no telemetry to opt out of and no remote account to close.",
];
