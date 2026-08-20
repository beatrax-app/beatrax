<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Services\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Identity\RecoveryCodesExportBridge;

// Hands the pending recovery codes to the OS share sheet, and says whether it
// worked. A GET with no CSRF token on purpose: a Livewire round-trip from the
// screen that shows the codes once has already been seen to 419 on device and
// take them with it. Holding the session that holds the codes is the gate.
/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class RecoveryCodesExportController
{
    public const string SESSION_KEY = 'auth.signup.recovery_codes_plain';

    public function __invoke(
        Session $session,
        RecoveryCodesExportBridge $bridge,
        RecoveryCodeFormatter $formatter,
        CurrentUser $currentUser,
    ): JsonResponse {
        /** @var mixed $pending */
        $pending = $session->get(self::SESSION_KEY);

        $codes = is_array($pending) ? array_values(array_filter($pending, is_string(...))) : [];

        if ($codes === []) {
            return new JsonResponse(['saved' => false], 409);
        }

        $saved = $bridge->export(
            $formatter->filenameFor($currentUser->user()->username),
            $formatter->format($codes),
            Lang::get('mobile::import.recovery_share_title'),
            Lang::get('mobile::import.recovery_share_message'),
        );

        // Left in the session either way. These are shown exactly once, so a
        // failed export must not also consume them.
        return new JsonResponse(['saved' => $saved], $saved ? 200 : 503);
    }
}
