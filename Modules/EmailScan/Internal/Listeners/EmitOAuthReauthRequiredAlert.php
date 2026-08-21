<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;

final class EmitOAuthReauthRequiredAlert
{
    private const REAUTH_KIND = 'oauth.reauth_required';

    private const BACKUP_FILENAME = 'email-oauth.json.pre-phase-12.bak';

    private const MESSAGE = 'OAuth secrets moved to per-user storage. Re-authorize Gmail and Microsoft to resume email scanning. The old secrets file was renamed to email-oauth.json.pre-phase-12.bak for rollback.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly CurrentUser $currentUser,
        private readonly DatabaseManager $db,
        private readonly UserDataPathService $paths,
        private readonly SystemAlertWriter $alerts,
    ) {}

    public function handle(): void
    {
        if ($this->shouldEmitAlert()) {
            $this->alerts->raiseForUser(
                userId: $this->currentUser->id(),
                kind: self::REAUTH_KIND,
                severity: 'warning',
                message: self::MESSAGE,
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
            ->where('kind', self::REAUTH_KIND)
            ->whereNull('acknowledged_at')
            ->exists();
    }
}
