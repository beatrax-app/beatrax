<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
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
    ) {}

    public function handle(): void
    {
        if (! $this->currentUser->isAuthenticated()) {
            return;
        }

        $backupPath = $this->paths->secrets().DIRECTORY_SEPARATOR.self::BACKUP_FILENAME;
        if (! $this->files->exists($backupPath)) {
            return;
        }

        $userId = $this->currentUser->id();
        $connection = $this->db->connection();

        // Raw Query Builder exists() calls, not Eloquent's
        // Model::query()->exists(), to clear PHPStan strict-rules
        // staticMethod.dynamicCall.
        $hasSecrets = $connection->table('oauth_secrets')
            ->where('user_id', $userId)
            ->exists();
        if ($hasSecrets) {
            return;
        }

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
