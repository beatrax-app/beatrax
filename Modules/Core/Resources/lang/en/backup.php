<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'This phone cannot save a file the app hands it, so the encrypted backup is made on the desktop app instead. Pair this device to keep the two in sync.',
        'unavailable' => "Encrypted backups are available on the desktop (SQLite) build. On a server database, use your database's own backup tooling.",
        'intro' => 'Download a passphrase-encrypted copy of your whole database — safe to keep on an external drive or in cloud storage, because it is unreadable without the passphrase (quantum-safe XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Passphrase',
        'confirm_passphrase' => 'Confirm passphrase',
        'keep_safe' => 'Keep the passphrase safe — there is no way to recover the backup without it.',
        'submit' => 'Download encrypted backup',
        'preparing' => 'Preparing…',
    ],

    'restore' => [
        'heading' => 'Restore from a backup',

        'intro_html' => 'Replace your current database with an encrypted backup. The file is decrypted and checked before anything changes, and a pre-restore snapshot of your current data is saved first — but this still <strong class="text-slate-700 dark:text-slate-200">overwrites everything</strong>, so it is gated. You will be signed out, because your sign-in lives in the database too.',
        'restored' => 'Your backup was restored. Sign in with the username and password that were in use when it was made.',
        'snapshot_saved_prefix' => 'A snapshot of your previous data was saved to',
        'file_label' => 'Encrypted backup (.enc)',
        'uploading' => 'Uploading…',
        'passphrase' => 'Passphrase',
        'confirm_prefix' => 'Type',
        'confirm_suffix' => 'to confirm',
        'submit' => 'Restore (overwrites current data)',
        'restoring' => 'Restoring…',
    ],

    'errors' => [
        'passphrase_min' => 'Use a passphrase of at least :min character.|Use a passphrase of at least :min characters.',
        'passphrase_mismatch' => 'The two passphrases do not match.',
        'download_sqlite_only' => 'Encrypted download is only available on the SQLite build.',
        'create_failed' => 'Could not create the backup: :message',
        'confirm_phrase' => 'Type :phrase to confirm — this replaces your current data.',
        'choose_file' => 'Choose an encrypted backup file (.enc) to restore.',
        'upload_failed' => 'The file did not finish uploading. It may be too large for this device — restoring in the desktop app accepts a bigger backup.',
        'enter_passphrase' => 'Enter the passphrase the backup was encrypted with.',
        'unreadable' => 'The uploaded file could not be read. Try again.',
    ],
];
