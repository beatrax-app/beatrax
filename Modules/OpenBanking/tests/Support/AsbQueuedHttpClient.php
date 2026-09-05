<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;

final class AsbQueuedHttpClient extends EnableBankingHttpClient
{
    public function __construct(
        EnableBankingJwtSigner $jwtSigner,
        private readonly MockHandler $mock,
    ) {
        parent::__construct($jwtSigner);
    }

    protected function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
    }
}
