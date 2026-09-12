<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Identity\DeviceSyncStandingReader;
use Psr\Log\LoggerInterface;
use Throwable;

// The single place that decides what an unavailable signing key costs. Every
// capture handler asks here, so "the writer could not be built" is answered
// once instead of eleven times — which is how ten of the eleven paths came to
// drop the mutation while the one somebody looked at did not.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
final readonly class OpCaptureSinkFactory
{
    // The other two are DeviceIdentityLoader::load() declining to turn a
    // genuine I/O fault into a state, which is correct of it and is why they
    // arrive here rather than as a missing identity.
    /** @var list<class-string<Throwable>> */
    private const array DEFERRABLE = [
        BindingResolutionException::class,
        SecretFileException::class,
        BackupIoException::class,
    ];

    // Resolved on demand, never injected: this factory is reached on every
    // mutation the app makes, and the identity loader and the queue reach a
    // file seal and a database that the signing path does not need at all.
    public function __construct(
        private Container $container,
        private LoggerInterface $log,
    ) {}

    // Five states cannot sign and all five defer. The fifth — the key-file
    // failing to READ — is no BindingResolutionException and used to escape to
    // the listener's last-resort catch, the one exit that owes nothing
    // afterwards: no deferred coordinate, no backfill, no edit for the peer.
    public function forUser(int $userId): OpCaptureSink
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (Throwable $e) {
            // Tested rather than caught by type: make() declares only
            // BindingResolutionException, so a typed multi-catch reads as dead
            // to the analyser even though the binding's closure raises all
            // three. Anything off the list leaves by the door it always did.
            foreach (self::DEFERRABLE as $deferrable) {
                if ($e instanceof $deferrable) {
                    return $this->withoutASigningKey($userId);
                }
            }

            throw $e;
        }
    }

    // The question is whether this device owes a peer, never whether it holds
    // a key-file: a restored database brings the old machine's self row and
    // never its key-file, and a device registered as a peer owes every write
    // it makes whether or not it can sign one yet.
    private function withoutASigningKey(int $userId): OpCaptureSink
    {
        $standing = $this->container->make(DeviceSyncStandingReader::class)->forUser($userId);

        if (! $standing->owesAPeerItsWrites()) {
            return new SyncOffOpSink($this->log);
        }

        return new DeferredOpCaptureSink($userId, $this->container->make(DeferredOpCaptures::class));
    }
}
