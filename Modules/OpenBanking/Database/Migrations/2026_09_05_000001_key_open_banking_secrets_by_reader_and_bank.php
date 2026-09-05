<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Psr\Log\LoggerInterface;

// Moves the pre-keying store — one file for the whole installation — into the
// per-reader file the repository now addresses, keeping the live consent so an
// installed reader is still connected afterwards and re-authorises nothing.
//
// The owner is derived, never guessed: the connection row carrying the stored
// institution says whose session it was. With no such row there is no consent
// to lose, so a lone account takes the application half and anything more
// ambiguous is left alone for the wizard to replace.
return new class extends ModuleMigration
{
    public function up(): void
    {
        /** @var OpenBankingSecretsRepository $secrets */
        $secrets = Container::getInstance()->make(OpenBankingSecretsRepository::class);

        try {
            $ownerId = $this->resolveOwner($secrets->legacyInstitutionId());
            if ($ownerId !== null) {
                $secrets->adoptLegacyFile($ownerId);
            }
        } catch (Throwable $e) {
            // Left exactly where it is, and said out loud: the settings screen
            // answers an unreadable store on screen, and a migration that
            // deleted it would turn a repairable file into a lost one.
            /** @var LoggerInterface $logger */
            $logger = Container::getInstance()->make(LoggerInterface::class);
            $logger->warning(
                'Open banking: the installation-wide secrets store could not be adopted into a reader keyed one.',
                SafeExceptionContext::describe($e),
            );
        }
    }

    public function down(): void
    {
        // Nothing to undo: the reader keyed file is what every code path now
        // reads, so restoring the global one would strand the connection.
    }

    private function resolveOwner(?string $institutionId): ?int
    {
        $connection = $this->db()->connection($this->getConnection());

        if ($institutionId !== null) {
            $ownerId = $connection->table('open_banking_connections')
                ->where('institution_id', $institutionId)
                ->orderByDesc('id')
                ->value('user_id');

            return is_numeric($ownerId) ? (int) $ownerId : null;
        }

        $userIds = $connection->table('users')->orderBy('id')->pluck('id');

        return $userIds->count() === 1 && is_numeric($userIds->first())
            ? (int) $userIds->first()
            : null;
    }
};
