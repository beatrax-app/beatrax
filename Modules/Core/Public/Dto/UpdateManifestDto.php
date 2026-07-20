<?php

declare(strict_types=1);

namespace Modules\Core\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Services\ElectronUpdateChannel;

/**
 * @see ElectronUpdateChannel::poll()
 */
final readonly class UpdateManifestDto
{
    public function __construct(
        public string $latestVersion,
        public string $sha512Hex,
        public CarbonImmutable $publishedAt,
        public string $channel,
    ) {}
}
