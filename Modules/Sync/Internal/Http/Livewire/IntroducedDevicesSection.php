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

// The device list's second half: keys a confirmed peer relayed for a device
// this household can no longer pair with. Its own component rather than a
// branch of the settings section, because the act it offers is a different and
// weaker one and its copy has to say so without borrowing pairing's language.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
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

    public function mount(CurrentUser $currentUser, DatabaseManager $db, DeviceIntroductionService $service): void
    {
        $this->introductions = $this->load($db, $service, $currentUser->user()->id);
    }

    public function confirmIntroduction(
        int|string $introductionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIntroductionService $service,
    ): void {
        $id = DerivedRowId::fromWire($introductionId);
        $userId = $currentUser->user()->id;

        // Zero is what a non-numeric payload decodes to, and it matches no row.
        // Skipping the call keeps a crafted id from reading as a failed write
        // rather than as the nothing it is.
        if ($id > 0) {
            $service->confirm($userId, $id);
        }

        $this->introductions = $this->load($db, $service, $userId);
    }

    public function dismissIntroduction(
        int|string $introductionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        DeviceIntroductionService $service,
    ): void {
        $id = DerivedRowId::fromWire($introductionId);
        $userId = $currentUser->user()->id;

        if ($id > 0) {
            $service->forget($userId, $id);
        }

        $this->introductions = $this->load($db, $service, $userId);
    }

    // Names, not ids, for the device that vouched — and the raw id when this
    // install has no row for it, which is honest rather than blank: the reader
    // is being asked who to trust, so an unnameable voucher must still show.
    /**
     * @return list<array<string, mixed>>
     */
    private function load(DatabaseManager $db, DeviceIntroductionService $service, int $userId): array
    {
        $rows = $service->forUser($userId);

        $names = $db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->pluck('name', 'device_id')
            ->all();

        $introductions = [];

        foreach ($rows as $row) {
            $vouchedBy = is_string($row->introduced_by_device_id) ? $row->introduced_by_device_id : '';
            $name = $names[$vouchedBy] ?? null;

            $introductions[] = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) && $row->name !== '' ? $row->name : $vouchedBy,
                'safety_number_words' => is_string($row->safety_number_words) ? $row->safety_number_words : '',
                'introduced_by' => is_string($name) && $name !== '' ? $name : $vouchedBy,
                'withheld' => is_numeric($row->withheld_entry_count) ? (int) $row->withheld_entry_count : 0,
                'confirmed' => $row->verification_confirmed_at !== null,
            ];
        }

        return $introductions;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('sync::livewire.introduced-devices-section');
    }
}
