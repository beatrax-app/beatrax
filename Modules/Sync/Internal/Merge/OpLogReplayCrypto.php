<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Optional crypto/keyring dependencies for OpLogReplayer, bundled into one
// value object so the replayer constructor stays within its parameter budget.
// Every field is nullable; a null field (or a null context) means "resolve
// from the container", preserving the fail-closed-outside-a-booted-app path.
final readonly class OpLogReplayCrypto
{
    public function __construct(
        public ?SensitiveFieldRegistry $sensitiveFields = null,
        public ?OpLogFieldCrypto $fieldCrypto = null,
        public ?GdkKeyringService $keyringService = null,
        public ?SensitiveColumnCodec $columnCodec = null,
        public ?Session $session = null,
    ) {}
}
