<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\StrandedEncryptionEpochException;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\PairingSafetyDigest;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// settleConfirmedPairing() runs once, on the !== Success -> Success edge, and
// no screen the phone offers afterwards re-enters migrate(): the encryption
// offer CTA is hidden the moment sync is on, and enableSync() returns early.
// So "a later pass will fix it" was never true, and the catch that relied on
// it left the phone holding a committed epoch it has no key for, silently.

const STRANDED_EPOCH_MESSAGE = 'Keyring finalize failed after commit for user 7: staged at /Users/reader/Library/beatrax/sync/gdk/7.enc.staged';

function strandedEpochUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('whatever-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function strandedEpochRecorder(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

// Null is the control: migrate() returns, and nothing on the success step or
// in the log may claim otherwise.
function strandedEpochMigrationService(?Throwable $thrown): EncryptionMigrationService
{
    return new class($thrown) extends EncryptionMigrationService
    {
        public function __construct(private readonly ?Throwable $thrown) {}

        public function migrate(User $user, Session $session): void
        {
            if ($this->thrown !== null) {
                throw $this->thrown;
            }
        }
    };
}

/**
 * The non-import CREATE-ACCOUNT ceremony, driven to the both-confirm edge
 * that settleConfirmedPairing() is reached on — and only on.
 *
 * @return array{component: Livewire\Features\SupportTesting\Testable, logger: LoggerInterface}
 */
function strandedEpochPairToSuccess(User $user, ?Throwable $thrown): array
{
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    $gateway->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    // A phone that already has at-rest encryption on, pairing another device.
    // migrate() is the idempotent re-entry there rather than the first pass,
    // and the stranded state is exactly what that re-entry exists to reconcile.
    app(DatabaseManager::class)->connection()->table('sync_encryption_state')->insert([
        'user_id' => $user->id,
        'current_epoch' => 1,
        'migration_in_progress' => false,
        'enabled_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $initiatorDeviceId = 'desktop-stranded-epoch';
    $initiatorEd = bin2hex(random_bytes(32));
    $initiatorKx = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(16));

    $gateway->seedResponderToken(
        $token,
        new PairingPeerIdentity($initiatorDeviceId, $initiatorEd, $initiatorKx),
        (int) $user->id,
    );

    // Written out rather than built: this is the string a camera hands the
    // phone, and every other scanner test on this side models it the same way.
    $qrPayload = 'beatrax://pair?v=1&token='.$token
        .'&ed='.$initiatorEd
        .'&kx='.$initiatorKx
        .'&device='.$initiatorDeviceId;

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('importing', false)
        ->call('submitCode', $qrPayload)
        ->assertSet('step', 'confirm');

    $component->call('confirmMatch')->assertSet('awaitingPeer', true);

    $pairingTokenId = (int) $component->get('pairingTokenId');
    $gateway->confirm($pairingTokenId, (int) $user->id, $initiatorDeviceId, PairingSafetyDigest::forToken($pairingTokenId, (int) $user->id));

    $logger = strandedEpochRecorder();
    app()->instance(LoggerInterface::class, $logger);
    app()->instance('log', $logger);

    app()->instance(EncryptionMigrationService::class, strandedEpochMigrationService($thrown));

    $component->call('checkPairingState')->assertSet('step', 'success');

    return ['component' => $component, 'logger' => $logger];
}

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $records
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function strandedEpochEncryptionWarnings(array $records): array
{
    return array_values(array_filter(
        $records,
        static fn (array $record): bool => $record['level'] === 'warning'
            && str_contains($record['message'], 'encryption'),
    ));
}

it('says a stranded epoch out loud instead of folding it into a paired screen', function (): void {
    $user = strandedEpochUser('mobile-pair-stranded-epoch');
    test()->actingAs($user);

    $settled = strandedEpochPairToSuccess($user, new StrandedEncryptionEpochException(STRANDED_EPOCH_MESSAGE));

    /** @var LoggerInterface&object{records: list<array{level: string, message: string, context: array<string, mixed>}>} $logger */
    $logger = $settled['logger'];

    $warnings = strandedEpochEncryptionWarnings($logger->records);

    expect($warnings)->toHaveCount(
        1,
        'The phone committed an epoch it has no key for and said nothing: no log line, and a success screen that reads as a finished pairing.',
    );

    // Named apart from an ordinary failure because only one of the two can be
    // retried by anything on this device.
    expect($warnings[0]['message'])->toContain('stranded');
});

it('shows the reader that protecting this device did not finish', function (): void {
    $user = strandedEpochUser('mobile-pair-stranded-notice');
    test()->actingAs($user);

    $settled = strandedEpochPairToSuccess($user, new StrandedEncryptionEpochException(STRANDED_EPOCH_MESSAGE));

    $settled['component']
        ->assertSet('encryptionActivationFailed', true)
        ->assertSee(Lang::get('mobile::pairing.encryption_incomplete'));
});

it('names the failure without the path the exception message carries', function (): void {
    $user = strandedEpochUser('mobile-pair-stranded-context');
    test()->actingAs($user);

    $settled = strandedEpochPairToSuccess($user, new StrandedEncryptionEpochException(STRANDED_EPOCH_MESSAGE));

    /** @var LoggerInterface&object{records: list<array{level: string, message: string, context: array<string, mixed>}>} $logger */
    $logger = $settled['logger'];

    $warnings = strandedEpochEncryptionWarnings($logger->records);
    $encoded = json_encode($warnings, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('/Users/reader/Library')
        ->and($warnings[0]['context']['reason'])->toBe(StrandedEncryptionEpochException::class);
});

it('leaves the notice off a pairing whose encryption did activate', function (): void {
    $user = strandedEpochUser('mobile-pair-encryption-ok');
    test()->actingAs($user);

    $settled = strandedEpochPairToSuccess($user, null);

    $settled['component']
        ->assertSet('encryptionActivationFailed', false)
        ->assertDontSee(Lang::get('mobile::pairing.encryption_incomplete'));

    /** @var LoggerInterface&object{records: list<array{level: string, message: string, context: array<string, mixed>}>} $logger */
    $logger = $settled['logger'];

    expect(strandedEpochEncryptionWarnings($logger->records))->toBe([]);
});
