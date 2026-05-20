<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Writes a one-time "re-authorize Gmail and Microsoft" warning to the
 * system_alerts table after OAuth secrets move to per-user storage.
 *
 * The signal that a move happened is the presence of the renamed
 * rollback file email-oauth.json.pre-phase-12.bak. When that file
 * exists and the current user has no oauth_secrets rows yet, the user
 * has not re-authorized any provider — so a warning is surfaced once.
 *
 * The check is cheap to skip: when the .bak file is absent it returns
 * immediately. A de-dup guard ensures a second invocation does not add
 * a duplicate un-acknowledged row, so it is safe to call on every
 * authenticated request.
 */
final class EmitOAuthReauthRequiredAlert
{
    private const REAUTH_KIND = 'oauth.reauth_required';

    private const BACKUP_RELATIVE = 'app/secrets/email-oauth.json.pre-phase-12.bak';

    private const MESSAGE = 'OAuth secrets moved to per-user storage. Re-authorize Gmail and Microsoft to resume email scanning. The old secrets file was renamed to email-oauth.json.pre-phase-12.bak for rollback.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly CurrentUser $currentUser,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        // Cheap pre-check: no rollback artefact means no move happened.
        if (! $this->files->exists(storage_path(self::BACKUP_RELATIVE))) {
            return;
        }

        $userId = $this->currentUser->id();
        $connection = $this->db->connection();

        // The raw Query Builder exists() calls are used instead of
        // Eloquent's Model::query()->exists() to clear PHPStan
        // strict-rules staticMethod.dynamicCall — the same pattern as
        // TransactionDetail's transaction-visibility pre-check.

        // The user has already re-authorized at least one provider.
        $hasSecrets = $connection->table('oauth_secrets')
            ->where('user_id', $userId)
            ->exists();
        if ($hasSecrets) {
            return;
        }

        // De-dup: an un-acknowledged warning is already on the banner.
        $alreadyAlerted = $connection->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', self::REAUTH_KIND)
            ->whereNull('acknowledged_at')
            ->exists();
        if ($alreadyAlerted) {
            return;
        }

        SystemAlert::query()->create([
            'user_id' => $userId,
            'kind' => self::REAUTH_KIND,
            'severity' => 'warning',
            'message' => self::MESSAGE,
        ]);
    }
}
