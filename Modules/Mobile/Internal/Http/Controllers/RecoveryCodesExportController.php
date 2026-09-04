<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Mobile\Internal\Actions\ExportRecoveryCodes;
use Modules\Mobile\Internal\Enums\RecoveryCodesExportOutcome;

// Hands the pending recovery codes to the OS share sheet, and says whether it
// worked. A GET with no CSRF token on purpose: a Livewire round-trip from the
// screen that shows the codes once has already been seen to 419 on device and
// take them with it. Holding the session that holds the codes is the gate.
final class RecoveryCodesExportController
{
    public const string SESSION_KEY = PendingRecoveryCodes::SESSION_KEY;

    public function __invoke(Session $session, ExportRecoveryCodes $export): JsonResponse
    {
        return match ($export($session)) {
            RecoveryCodesExportOutcome::NoPendingCodes => new JsonResponse(['saved' => false], 409),
            RecoveryCodesExportOutcome::Shared => new JsonResponse(['saved' => true], 200),
            RecoveryCodesExportOutcome::NotShared => new JsonResponse(['saved' => false], 503),
        };
    }
}
