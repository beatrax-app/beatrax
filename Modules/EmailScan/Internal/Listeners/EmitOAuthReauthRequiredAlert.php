<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;

final readonly class EmitOAuthReauthRequiredAlert
{
    private const string BACKUP_FILENAME = 'email-oauth.json.pre-phase-12.bak';

    public function __construct(
        private Filesystem $files,
        private CurrentUser $currentUser,
        private DatabaseManager $db,
        private UserDataPathService $paths,
        private SystemAlertWriter $alerts,
    ) {}

    public function handle(): void
    {
        if ($this->shouldEmitAlert()) {
            // The filename is a path on disk, not copy, so it is substituted
            // into the banner's line exactly as the banner substitutes it.
            $line = CopyLine::of('core::alerts.messages.oauth_reauth_required', ['file' => self::BACKUP_FILENAME]);

            $this->alerts->raiseForUser(
                userId: $this->currentUser->id(),
                kind: OAuthAlertKind::ReauthRequired->value,
                severity: SystemAlertSeverity::Warning->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line),
            );
        }
    }

    private function shouldEmitAlert(): bool
    {
        if (! $this->currentUser->isAuthenticated()) {
            return false;
        }

        $backupPath = $this->paths->secrets().DIRECTORY_SEPARATOR.self::BACKUP_FILENAME;
        if (! $this->files->exists($backupPath)) {
            return false;
        }

        return ! $this->userAlreadyHandled();
    }

    // Handled = re-authorized (an oauth_secrets row exists) or already
    // holding an open reauth alert.
    private function userAlreadyHandled(): bool
    {
        $userId = $this->currentUser->id();
        $connection = $this->db->connection();

        // Query Builder rather than Model::query(): PHPStan strict-rules
        // flags staticMethod.dynamicCall on the latter.
        $hasSecrets = $connection->table('oauth_secrets')
            ->where('user_id', $userId)
            ->exists();
        if ($hasSecrets) {
            return true;
        }

        return $connection->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', OAuthAlertKind::ReauthRequired->value)
            ->whereNull('acknowledged_at')
            ->exists();
    }
}
