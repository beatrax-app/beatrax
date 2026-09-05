<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

// ON DELETE CASCADE clears user_id when the owner account goes away. Doing it
// from inside fetch() is that happening while the attempt is in flight.
final class SojaOrphaningRemoteSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly int $connectionId,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->db->connection()->table('open_banking_connections')
            ->where('id', $this->connectionId)
            ->update(['user_id' => null]);

        yield from [];

        return FetchWalk::exhausted();
    }
}
