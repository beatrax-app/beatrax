<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

// OpLogWriter takes four runtime primitives no autowiring can supply — the
// device id, the user id and the signing pair — and they come from an identity
// only an unlocked session can open. Built per resolve, never held: the
// collaborators of the process asking are the ones the writer must sign with.
final readonly class OpLogWriterFactory
{
    public function __construct(private Container $app) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws BindingResolutionException when no credentials are available or the given ones are incomplete.
     */
    public function make(array $parameters): OpLogWriter
    {
        return $parameters === []
            ? $this->forCurrentUser()
            : $this->withCredentials($parameters);
    }

    // Throws (not returns null) when no identity is available: an unlocked
    // key is a precondition for signing, and callers already treat a failed
    // resolution as "capture is not possible right now".
    /**
     * @throws BindingResolutionException when sync is off, locked, or the
     *                                    request has no authenticated user.
     */
    private function forCurrentUser(): OpLogWriter
    {
        $currentUser = $this->app->make(CurrentUser::class);

        if (! $currentUser->isAuthenticated()) {
            throw new BindingResolutionException('OpLogWriter: no authenticated user to capture for.');
        }

        $userId = $currentUser->id();
        $sessionFactory = $this->app->make(SessionFactory::class);
        $identity = $this->app->make(DeviceIdentityLoader::class)->load($userId, $sessionFactory());

        if ($identity === null) {
            throw new BindingResolutionException('OpLogWriter: no usable device identity (sync off or locked).');
        }

        return $this->build(
            $identity->deviceId,
            $userId,
            sodium_hex2bin($identity->ed25519SecretKeyHex),
            sodium_hex2bin($identity->ed25519PublicKeyHex),
        );
    }

    // Callers that already hold credentials (tests, and any future
    // multi-identity caller) pass them explicitly to app(); honouring that
    // keeps the make-with-parameters contract this binding replaced.
    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws BindingResolutionException when a credential is missing or the wrong type.
     */
    private function withCredentials(array $parameters): OpLogWriter
    {
        $deviceId = $parameters['deviceId'] ?? null;
        $userId = $parameters['userId'] ?? null;
        $secretKey = $parameters['secretKey'] ?? null;
        $publicKey = $parameters['publicKey'] ?? null;

        if (! is_string($deviceId) || ! is_int($userId) || ! is_string($secretKey) || ! is_string($publicKey)) {
            throw new BindingResolutionException('OpLogWriter: explicit credentials are incomplete.');
        }

        return $this->build($deviceId, $userId, $secretKey, $publicKey);
    }

    private function build(string $deviceId, int $userId, string $secretKey, string $publicKey): OpLogWriter
    {
        return new OpLogWriter(
            clock: $this->app->make(HybridLogicalClock::class),
            db: $this->app->make(DatabaseManager::class),
            signer: $this->app->make(DeviceKeySigner::class),
            wallClock: $this->app->make(Clock::class),
            deviceId: $deviceId,
            userId: $userId,
            secretKey: $secretKey,
            publicKey: $publicKey,
            sensitiveFields: $this->app->make(SensitiveFieldRegistry::class),
            fieldCrypto: $this->app->make(OpLogFieldCrypto::class),
            rules: $this->app->make(MergeRulesRegistry::class),
            keyring: $this->app->make(GdkKeyringService::class),
            session: $this->app->make(SessionFactory::class),
        );
    }
}
