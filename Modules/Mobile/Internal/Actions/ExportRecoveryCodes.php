<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Actions;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Enums\RecoveryCodesExportOutcome;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

final readonly class ExportRecoveryCodes
{
    public function __construct(
        private ShareSheetExport $bridge,
        private RecoveryCodeFormatter $formatter,
        private CurrentUser $currentUser,
    ) {}

    // The codes are left in the session on every path. They are shown exactly
    // once, so a failed export must not also consume them.
    public function __invoke(Session $session): RecoveryCodesExportOutcome
    {
        $codes = PendingRecoveryCodes::read($session);

        if ($codes === []) {
            return RecoveryCodesExportOutcome::NoPendingCodes;
        }

        // Saving is part of the ceremony, so it renews the codes rather than
        // spending them: a reader who saves and then reads on is still on the
        // one screen that shows them.
        PendingRecoveryCodes::renew($session);

        $shared = $this->bridge->export(
            $this->formatter->filenameFor($this->currentUser->user()->username),
            $this->formatter->format($codes),
            Lang::get('mobile::import.recovery_share_title'),
            Lang::get('mobile::import.recovery_share_message'),
        ) === FileExportOutcome::Shared;

        return $shared ? RecoveryCodesExportOutcome::Shared : RecoveryCodesExportOutcome::NotShared;
    }
}
