<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Dto\FetchWindow;

final class ParityStubHttpClient extends EnableBankingHttpClient
{
    /**
     * @param  array<string, mixed>  $transactionsResponse
     * @param  array<string, mixed>  $accountDetailsResponse
     */
    public function __construct(
        private readonly array $transactionsResponse,
        private readonly array $accountDetailsResponse,
    ) {}

    public function accountDetails(string $uid): array
    {
        return $this->accountDetailsResponse;
    }

    public function transactions(string $uid, FetchWindow $window, ?string $continuationKey = null): array
    {
        return $this->transactionsResponse;
    }
}
