<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\StrandedEncryptionEpochException;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// A collaborator rather than two more methods on the component, which already
// sits on the analyser's method ceiling.
final readonly class PairingEncryptionActivation
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    // True once at-rest encryption is active for this user. False is never
    // silent, and it says WHICH failure it was: the pairing itself stands
    // either way, so nothing else on the screen distinguishes them.
    public function activated(
        EncryptionMigrationService $migrationService,
        User $user,
        Session $session,
    ): bool {
        try {
            $migrationService->migrate($user, $session);
        } catch (StrandedEncryptionEpochException $e) {
            return $this->stranded($e, $user->id);
        } catch (Throwable $e) {
            return $this->failed($e, $user->id);
        }

        return true;
    }

    // `current_epoch` is committed with no keyring behind it. migrate() kept
    // the staged key for a retry, and this device offers no way to ask for
    // one: the encryption CTA is hidden from the moment sync is on.
    private function stranded(StrandedEncryptionEpochException $e, int $userId): bool
    {
        $this->logger->warning(
            'PairingEncryptionActivation: at-rest encryption stranded — the epoch is committed but its keyring is not in place, and migrate() must be re-run to reconcile.',
            ['user_id' => $userId, ...SafeExceptionContext::describe($e)],
        );

        return false;
    }

    private function failed(Throwable $e, int $userId): bool
    {
        $this->logger->warning(
            'PairingEncryptionActivation: at-rest encryption auto-activation failed — the migration rolled back and the pairing stands.',
            ['user_id' => $userId, ...SafeExceptionContext::describe($e)],
        );

        return false;
    }
}
