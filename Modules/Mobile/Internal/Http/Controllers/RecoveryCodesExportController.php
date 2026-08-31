<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

// Hands the pending recovery codes to the OS share sheet, and says whether it
// worked. A GET with no CSRF token on purpose: a Livewire round-trip from the
// screen that shows the codes once has already been seen to 419 on device and
// take them with it. Holding the session that holds the codes is the gate.
final class RecoveryCodesExportController
{
    public const string SESSION_KEY = PendingRecoveryCodes::SESSION_KEY;

    public function __invoke(
        Session $session,
        ShareSheetExport $bridge,
        RecoveryCodeFormatter $formatter,
        CurrentUser $currentUser,
    ): JsonResponse {
        $codes = PendingRecoveryCodes::read($session);

        if ($codes === []) {
            return new JsonResponse(['saved' => false], 409);
        }

        // Saving is part of the ceremony, so it renews the codes rather than
        // spending them: a reader who saves and then reads on is still on the
        // one screen that shows them.
        PendingRecoveryCodes::renew($session);

        $saved = $bridge->export(
            $formatter->filenameFor($currentUser->user()->username),
            $formatter->format($codes),
            Lang::get('mobile::import.recovery_share_title'),
            Lang::get('mobile::import.recovery_share_message'),
        ) === FileExportOutcome::Shared;

        // Left in the session either way. These are shown exactly once, so a
        // failed export must not also consume them.
        return new JsonResponse(['saved' => $saved], $saved ? 200 : 503);
    }
}
