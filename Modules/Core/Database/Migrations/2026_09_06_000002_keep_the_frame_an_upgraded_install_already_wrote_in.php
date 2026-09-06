<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// `app.timezone` is the frame every DATETIME column is written in, and until
// 2026-09-06 every shipped bundle pinned it to Europe/Amsterdam. With the pin
// gone the zone resolves from the machine, which is right for a new install and
// wrong for an old one: its rows were written at +01:00/+02:00, and a reader
// elsewhere would have every stored timestamp shift under them on upgrade.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
return new class extends ModuleMigration
{
    // The only value any shipped bundle carried. An install that already holds
    // an account wrote its rows in this frame, whatever machine it runs on now.
    private const string THE_ZONE_EVERY_BUNDLE_PINNED = 'Europe/Amsterdam';

    public function up(): void
    {
        if (! $this->schema()->hasTable('users')) {
            return;
        }

        $connection = $this->schema()->getConnection();

        // InstallCommand migrates and only then creates the account, so an
        // account existing here means this install predates the migration —
        // a first run reaches `ensureUser()` with the column already added,
        // keeps NULL, and reads the machine, which is the point of the change.
        $owner = $connection->table('users')->orderBy('id')->value('id');

        if ($owner === null) {
            return;
        }

        // Only the owner row: it is the one `InstallTimezone::chosen()` reads,
        // and the column travels, so a peer inherits this frame on backfill
        // rather than resolving its own and diverging from the rows it holds.
        $connection->table('users')
            ->where('id', $owner)
            ->whereNull('timezone')
            ->update(['timezone' => self::THE_ZONE_EVERY_BUNDLE_PINNED]);
    }

    // Deliberately not reversible in the sense of restoring NULL: rolling back
    // would hand the install's frame to whatever machine it is on, which is the
    // reinterpretation this migration exists to prevent.
    public function down(): void {}
};
