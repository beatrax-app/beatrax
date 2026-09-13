<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Public\Support\UnicodeFolding;

// A queued job runs on a connection the worker resolved for itself, not the one
// the request that queued it held. What it folded is kept on a static rather
// than returned, because the worker throws its return value away.
final class FoldedProbeJob implements ShouldQueue
{
    use Queueable;

    public static ?string $folded = null;

    public function handle(DatabaseManager $db): void
    {
        $row = $db->connection()->selectOne(
            'select '.UnicodeFolding::sql('?').' as folded',
            ['MÖRK ΑΘΗΝΑ ЩУКА'],
        );

        self::$folded = is_object($row) && is_string($row->folded ?? null) ? $row->folded : null;
    }
}
