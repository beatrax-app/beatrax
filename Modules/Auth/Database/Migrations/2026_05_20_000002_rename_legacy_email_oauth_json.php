<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Filesystem\Filesystem;

/**
 * Renames the legacy single-file OAuth secrets store out of the way.
 *
 * OAuth client credentials and inbox refresh tokens now live in the
 * per-user oauth_secrets table. The previous installation kept them in
 * a single shared JSON file at storage/app/secrets/email-oauth.json.
 * This migration renames that file to email-oauth.json.pre-phase-12.bak
 * (mode 0600) so the operator retains a rollback artefact, and writes a
 * README alongside it describing the rename and the recovery path. The
 * application never reads .bak files; re-authorizing Gmail and
 * Microsoft repopulates the table.
 *
 * The rename is filesystem-only — it touches no database table — and is
 * idempotent: it runs only when the legacy file is present and the .bak
 * target is absent, so a second run is a no-op. There is no rollback;
 * down() is intentionally empty.
 */
return new class extends Migration
{
    private const LEGACY_RELATIVE = 'app/secrets/email-oauth.json';

    private const BACKUP_RELATIVE = 'app/secrets/email-oauth.json.pre-phase-12.bak';

    private const README_RELATIVE = 'app/secrets/README.md';

    public function up(): void
    {
        $files = $this->files();
        $legacy = storage_path(self::LEGACY_RELATIVE);
        $backup = storage_path(self::BACKUP_RELATIVE);

        if (! $files->exists($legacy) || $files->exists($backup)) {
            return;
        }

        $files->move($legacy, $backup);
        $files->chmod($backup, 0600);
        $files->put(storage_path(self::README_RELATIVE), $this->readmeBody());
    }

    public function down(): void
    {
        // One-way: the renamed file is a rollback artefact the
        // application never reads. Nothing to reverse.
    }

    private function files(): Filesystem
    {
        /** @var Filesystem $files */
        $files = Container::getInstance()->make(Filesystem::class);

        return $files;
    }

    private function readmeBody(): string
    {
        return <<<'MD'
        # OAuth secrets storage

        OAuth client credentials and inbox refresh tokens are stored in the
        per-user `oauth_secrets` database table. Sensitive columns are
        encrypted at rest with the application key.

        ## email-oauth.json.pre-phase-12.bak

        The file `email-oauth.json.pre-phase-12.bak` is the previous
        single-file secrets store, renamed during the move to per-user
        storage. The application never reads it — it is kept only as a
        rollback artefact.

        To roll back, stop the application, rename the `.bak` file back to
        `email-oauth.json`, and restore the previous application version.
        Otherwise re-authorize Gmail and Microsoft from the Inboxes page;
        once that is done the `.bak` file can be deleted.
        MD;
    }
};
