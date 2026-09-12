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
    /**
     * What a device answers for rather than propagates. BindingResolutionException
     * is the writer having no credentials to build from; the other two are
     * DeviceIdentityLoader::load() declining to turn a genuine I/O fault into a
     * state, which is correct of it and is why they arrive here.
     *
     * @var list<class-string<Throwable>>
     */
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

    // Five states cannot sign and all five defer: a console with no session,
    // an engaged app-lock, a key-file no key in this database opens, a
    // restored database whose self row names a key-file that never travelled,
    // and the key-file failing to READ at all.
    //
    // The last one is not a BindingResolutionException and used to escape here.
    // DeviceIdentityLoader::load() declares it — the loader turns "will not
    // open" into a state and leaves a genuine I/O fault as a throw — so an
    // EMFILE, a permissions blip or a full volume at the moment of a write
    // reached the listener's last-resort catch, which logs and returns. That
    // is the one path where nothing is owed afterwards: no deferred coordinate,
    // no backfill, and the edit never reaches the peer.
    public function forUser(int $userId): OpCaptureSink
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (Throwable $e) {
            // Tested against the list rather than caught by type, because the
            // container is where the real throw surface stops being visible:
            // make() declares BindingResolutionException alone, so a typed
            // multi-catch here reads as dead to the analyser even though the
            // binding runs a closure that raises all three. Anything not on
            // the list still leaves by the same door it always did.
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
