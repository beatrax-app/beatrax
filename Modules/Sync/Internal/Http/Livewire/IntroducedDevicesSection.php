<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Sync\Internal\Pairing\DeviceIntroductionService;
use Modules\Sync\Internal\Transport\WithheldLedger;
use Modules\Sync\Public\Services\DeviceRegistryService;

// The device list's second half: keys a confirmed peer relayed for a device
// this household can no longer pair with. Its own component rather than a
// branch of the settings section, because the act it offers is a different and
// weaker one and its copy has to say so without borrowing pairing's language.
/**
 * @link ../../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final class IntroducedDevicesSection extends Component
{
    // Server-owned: every field here is read back by confirm() and dismiss()
    // through an authoritative, user-scoped lookup, and the row id is the only
    // thing either takes from the client at all.
    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $introductions = [];

    // The other half of the same report, and the half with no button on it: a
    // peer is holding history back and nothing here has offered a key for its
    // author. Said anyway, because a narrowing nobody states is the failure the
    // whole exchange exists to remove.
    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $withheld = [];

    public function mount(
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIntroductionService $service,
        WithheldLedger $ledger,
        DeviceRegistryService $registry,
    ): void {
        $this->reload($db, $service, $ledger, $registry, $currentUser->user()->id);
    }

    public function confirmIntroduction(
        int|string $introductionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIntroductionService $service,
        WithheldLedger $ledger,
        DeviceRegistryService $registry,
    ): void {
        $id = DerivedRowId::fromWire($introductionId);
        $userId = $currentUser->user()->id;

        // Zero is what a non-numeric payload decodes to, and it matches no row.
        // Skipping the call keeps a crafted id from reading as a failed write
        // rather than as the nothing it is.
        if ($id > 0) {
            $service->confirm($userId, $id);
        }

        $this->reload($db, $service, $ledger, $registry, $userId);
    }

    public function dismissIntroduction(
        int|string $introductionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIntroductionService $service,
        WithheldLedger $ledger,
        DeviceRegistryService $registry,
    ): void {
        $id = DerivedRowId::fromWire($introductionId);
        $userId = $currentUser->user()->id;

        if ($id > 0) {
            $service->forget($userId, $id);
        }

        $this->reload($db, $service, $ledger, $registry, $userId);
    }

    private function reload(
        DatabaseManager $db,
        DeviceIntroductionService $service,
        WithheldLedger $ledger,
        DeviceRegistryService $registry,
        int $userId,
    ): void {
        $names = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->pluck('name', 'device_id')
            ->all();

        $rows = $service->forUser($userId);
        $counts = $ledger->forUser($userId);

        $this->introductions = $this->introductionsFrom($rows, $names, $counts);
        $this->withheld = $this->heldBackFrom($rows, $names, $counts, $registry->deviceKeys($userId));
    }

    // Names, not ids, for the device that vouched — and the raw id when this
    // install has no row for it, which is honest rather than blank: the reader
    // is being asked who to trust, so an unnameable voucher must still show.
    /**
     * @param  array<int, \stdClass>  $rows
     * @param  array<array-key, mixed>  $names
     * @param  list<array{peer_device_id: string, author_device_id: string, entry_count: int}>  $counts
     * @return list<array<string, mixed>>
     */
    private function introductionsFrom(array $rows, array $names, array $counts): array
    {
        $introductions = [];

        foreach ($rows as $row) {
            $vouchedBy = is_string($row->introduced_by_device_id) ? $row->introduced_by_device_id : '';
            $name = $names[$vouchedBy] ?? null;
            $deviceId = is_string($row->device_id) ? $row->device_id : '';

            $introductions[] = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) && $row->name !== '' ? $row->name : $vouchedBy,
                'safety_number_words' => is_string($row->safety_number_words) ? $row->safety_number_words : '',
                'introduced_by' => is_string($name) && $name !== '' ? $name : $vouchedBy,
                'withheld' => $this->countFor($counts, $vouchedBy, $deviceId),
                'confirmed' => $row->verification_confirmed_at !== null,
            ];
        }

        return $introductions;
    }

    // What is left once every author this device can already read is accounted
    // for. Reported per peer rather than summed, because two peers holding the
    // same author's work back are two separate facts and adding them would
    // count it twice.
    /**
     * @param  array<int, \stdClass>  $rows
     * @param  array<array-key, mixed>  $names
     * @param  list<array{peer_device_id: string, author_device_id: string, entry_count: int}>  $counts
     * @param  array<string, string>  $paired  Authors a two-party ceremony already answers for.
     * @return list<array<string, mixed>>
     */
    private function heldBackFrom(array $rows, array $names, array $counts, array $paired): array
    {
        $introduced = array_map(
            static fn (\stdClass $row): string => is_string($row->device_id) ? $row->device_id : '',
            $rows,
        );
        $held = [];

        foreach ($counts as $count) {
            // A report is a claim about the exchange that carried it. An author
            // this device can now verify is one the NEXT exchange withholds
            // nothing for, so repeating it here would state a narrowing that
            // has already ended.
            if (in_array($count['author_device_id'], $introduced, true)
                || isset($paired[$count['author_device_id']])) {
                continue;
            }

            $author = $names[$count['author_device_id']] ?? null;
            $peer = $names[$count['peer_device_id']] ?? null;

            $held[] = [
                'author' => is_string($author) && $author !== '' ? $author : $count['author_device_id'],
                'peer' => is_string($peer) && $peer !== '' ? $peer : $count['peer_device_id'],
                'count' => $count['entry_count'],
            ];
        }

        return $held;
    }

    /**
     * @param  list<array{peer_device_id: string, author_device_id: string, entry_count: int}>  $counts
     */
    private function countFor(array $counts, string $peerDeviceId, string $authorDeviceId): int
    {
        foreach ($counts as $count) {
            if ($count['peer_device_id'] === $peerDeviceId && $count['author_device_id'] === $authorDeviceId) {
                return $count['entry_count'];
            }
        }

        return 0;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.introduced-devices-section');
    }
}
