<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Generator;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;

// A revoked PSD2 session leaves consent_expires_at months in the future, so the
// calendar alone says everything is fine while no data moves at all. The reader
// has no other way to find out.
final class ArcsRefusingRemoteSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(private readonly bool $refuse = true) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        if ($this->refuse) {
            throw EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 401, 'session revoked');
        }

        yield from [];

        return FetchWalk::exhausted();
    }
}
