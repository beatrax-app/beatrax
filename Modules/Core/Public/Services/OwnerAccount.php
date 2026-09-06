<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// One answer to one question: which row do the settings that belong to the
// INSTALLATION rather than to a reader live on. Three services had each worked
// it out for themselves, with three different ideas about a missing table, and
// a fourth was about to be written for the rebuild command.
final readonly class OwnerAccount
{
    // The logger is optional and every caller passes one. It is optional so a
    // migration or a boot path that has no logger yet can still ask, and it is
    // passed everywhere because a column that has genuinely gone missing
    // should not be indistinguishable from a first install.
    public function __construct(
        private DatabaseManager $db,
        private ?LoggerInterface $logger = null,
    ) {}

    public function id(): ?int
    {
        $id = $this->column('id');

        return is_numeric($id) ? (int) $id : null;
    }

    // Throwable rather than QueryException: this is read during boot and during
    // a migration run, both of which reach it before the table exists, and a
    // read that fails for any reason has the same answer — there is no owner to
    // report. Two of the three callers already caught the wider one.
    public function column(string $name): mixed
    {
        try {
            return $this->db->connection()->table('users')->orderBy('id')->value($name);
        } catch (Throwable $e) {
            $this->logger?->warning(
                'OwnerAccount: could not read the owner row; treating the installation as having no owner yet.',
                SafeExceptionContext::describe($e) + ['column' => $name],
            );

            return null;
        }
    }
}
