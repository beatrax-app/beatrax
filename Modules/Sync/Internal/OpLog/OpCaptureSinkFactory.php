<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Psr\Log\LoggerInterface;

// The single place that decides what an unavailable signing key costs. Every
// capture handler asks here, so "the writer could not be built" is answered
// once instead of eleven times — which is how ten of the eleven paths came to
// drop the mutation while the one somebody looked at did not.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
final readonly class OpCaptureSinkFactory
{
    // Resolved on demand, never injected: this factory is reached on every
    // mutation the app makes, and the identity loader and the queue reach a
    // file seal and a database that the signing path does not need at all.
    public function __construct(
        private Container $container,
        private LoggerInterface $log,
    ) {}

    // A device holding an identity it cannot currently open is the deferring
    // case, and that covers all three of them: a console with no session, an
    // engaged app-lock, and a key-file no key in this database opens.
    public function forUser(int $userId): OpCaptureSink
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (BindingResolutionException) {
            return $this->withoutASigningKey($userId);
        }
    }

    private function withoutASigningKey(int $userId): OpCaptureSink
    {
        if (! $this->container->make(DeviceIdentityLoader::class)->exists($userId)) {
            return new SyncOffOpSink($this->log);
        }

        return new DeferredOpCaptureSink($userId, $this->container->make(DeferredOpCaptures::class));
    }
}
