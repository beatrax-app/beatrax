<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Support\HostTimezone;
use Psr\Log\LoggerInterface;

// Answers which zone this installation reads and writes its days in. It is one
// answer per install rather than per reader: `app.timezone` is the frame a
// DATETIME column is written in, so two people on one ledger choosing
// differently would store two different strings for the same instant.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class InstallTimezone
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function owner(): OwnerAccount
    {
        return new OwnerAccount($this->db, $this->logger);
    }

    // An environment naming a zone wins, then the stored choice, then the
    // machine. Nothing seeds the row for that last arm on purpose: a device
    // that wrote its host zone down at signup would win the merge against the
    // install it later paired with, which would adopt the joiner's zone.
    public function zone(): string
    {
        $pinned = $this->config->get('app.timezone_pinned');

        if (is_string($pinned) && HostTimezone::isZone($pinned)) {
            return $pinned;
        }

        return $this->chosen() ?? HostTimezone::detect();
    }

    // The choice of not choosing, so the settings control has a value to name
    // the machine's own zone with. Stored as NULL, the same shape the locale
    // switcher's "system" uses.
    public const string THIS_MACHINE = 'host';

    // Read from the owner's row and nobody else's. The zone is one answer per
    // installation, and a household where two readers each held one would have
    // the same ledger written in two frames — so the account that owns the
    // install owns the answer, and the control writes there whoever opened it.
    public function chosen(): ?string
    {
        $stored = $this->ownerColumn();

        return is_string($stored) && HostTimezone::isZone($stored) ? $stored : null;
    }

    // NULL clears the choice, which hands the answer back to the machine.
    public function choose(WriteUserPreference $write, ?string $zone): void
    {
        $owner = $this->ownerId();

        if ($owner === null || ($zone !== null && ! HostTimezone::isZone($zone))) {
            return;
        }

        ($write)($owner, ['timezone' => $zone]);

        $this->apply($this->zone());
    }

    public function ownerId(): ?int
    {
        return $this->owner()->id();
    }

    private function ownerColumn(): mixed
    {
        return $this->owner()->column('timezone');
    }

    // Both, because they are read by different callers and neither reads the
    // other: `Instant` and Carbon take the process default, and the developer
    // console and the framework's own date casting read the config value.
    public function apply(string $zone): void
    {
        $this->config->set('app.timezone', $zone);
        date_default_timezone_set($zone);
    }
}
