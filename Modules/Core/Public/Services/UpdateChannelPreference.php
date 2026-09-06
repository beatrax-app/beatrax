<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Enums\UpdateChannel;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md#the-two-channels
 */
final readonly class UpdateChannelPreference
{
    public const string COLUMN = 'update_channel';

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    private function owner(): OwnerAccount
    {
        return new OwnerAccount($this->db, $this->logger);
    }

    // The owner's row and nobody else's, the way the install's timezone is:
    // which manifest set this bundle asks for is one answer per installation.
    // Device-local in the merge registry all the same — that says the answer
    // stays here, this says whose it is, and both are true at once.
    public function channel(): UpdateChannel
    {
        return UpdateChannel::fromStored($this->storedValue());
    }

    // Writes to the owner's row whoever opened the control, for the same reason
    // the read does. Nothing to write to before the first account exists.
    public function choose(WriteUserPreference $write, UpdateChannel $channel): void
    {
        $owner = $this->ownerId();

        if ($owner === null) {
            return;
        }

        ($write)($owner, [self::COLUMN => $channel->value]);
    }

    private function ownerId(): ?int
    {
        return $this->owner()->id();
    }

    private function storedValue(): ?string
    {
        $stored = $this->ownerColumn(self::COLUMN);

        return is_string($stored) ? $stored : null;
    }

    private function ownerColumn(string $column): mixed
    {
        return $this->owner()->column($column);
    }
}
