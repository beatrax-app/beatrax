<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\StrandedEncryptionEpochException;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Crypto\EncryptionSetupStep;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// migrate() draws a line at the commit. Before it, a throw has rolled every
// write back and restored the snapshot. After it, the service says plainly
// that it does NOT restore plaintext — `current_epoch` stands over sealed rows
// whose keyring never landed, and only re-running migrate() reconciles it.
//
// The settings path caught both with one bare `catch (\Throwable)` that bound
// no variable and wrote no line, so the second state had no record anywhere.
// The pairing path on the phone has kept the two apart since it was written.

const SETTINGS_STRANDED_MESSAGE = 'Keyring finalize failed after commit for user 3: staged at /Users/reader/Library/beatrax/sync/gdk/3.enc.staged';

function settingsStrandedUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('devices-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath('sync/'.$directory.'/'.$user->id.'.enc*')) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
}

function settingsStrandedRecorder(): LoggerInterface
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

// Null is the control: migrate() returns and nothing may be written about it.
function settingsStrandedMigrationService(?Throwable $thrown): EncryptionMigrationService
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

        public function progress(int $userId): int
        {
            return 100;
        }
    };
}

/**
 * @return array{records: list<array{level: string, message: string, context: array<string, mixed>}>}
 */
function settingsEnableEncryption(User $user, ?Throwable $thrown): array
{
    $logger = settingsStrandedRecorder();
    app()->instance(LoggerInterface::class, $logger);
    app()->instance('log', $logger);
    app()->instance(EncryptionMigrationService::class, settingsStrandedMigrationService($thrown));

    Livewire::test(DevicesAndSyncSettingsSection::class)->call('enableEncryption');

    /** @var object{records: list<array{level: string, message: string, context: array<string, mixed>}>} $logger */
    return ['records' => $logger->records];
}

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $records
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function settingsEncryptionWarnings(array $records): array
{
    return array_values(array_filter(
        $records,
        static fn (array $record): bool => $record['level'] === 'warning'
            && str_contains($record['message'], 'encryption'),
    ));
}

it('writes a line naming the stranded epoch rather than closing over it', function (): void {
    $user = settingsStrandedUser('settings-stranded-epoch');
    test()->actingAs($user);

    $warnings = settingsEncryptionWarnings(
        settingsEnableEncryption($user, new StrandedEncryptionEpochException(SETTINGS_STRANDED_MESSAGE))['records'],
    );

    expect($warnings)->toHaveCount(
        1,
        'The settings path committed an epoch with no keyring behind it and wrote nothing at all, so the only account of it was a modal the reader then dismissed.',
    );

    // Named apart from a rollback because only one of the two leaves anything
    // behind to reconcile.
    expect($warnings[0]['message'])->toContain('stranded');
});

it('names a rolled-back failure too, and does not call it stranded', function (): void {
    $user = settingsStrandedUser('settings-rolled-back');
    test()->actingAs($user);

    $warnings = settingsEncryptionWarnings(
        settingsEnableEncryption($user, new RuntimeException('The migration could not start.'))['records'],
    );

    expect($warnings)->toHaveCount(1, 'a failure that rolled back is still a failure the reader may report')
        ->and($warnings[0]['message'])->not->toContain('stranded');
});

it('carries the cause without the path the exception message spells out', function (): void {
    $user = settingsStrandedUser('settings-stranded-context');
    test()->actingAs($user);

    $warnings = settingsEncryptionWarnings(
        settingsEnableEncryption($user, new StrandedEncryptionEpochException(SETTINGS_STRANDED_MESSAGE))['records'],
    );

    expect(json_encode($warnings, JSON_THROW_ON_ERROR))->not->toContain('/Users/reader/Library')
        ->and($warnings[0]['context']['reason'])->toBe(StrandedEncryptionEpochException::class);
});

it('writes nothing when the migration finishes', function (): void {
    $user = settingsStrandedUser('settings-encryption-ok');
    test()->actingAs($user);

    expect(settingsEncryptionWarnings(settingsEnableEncryption($user, null)['records']))->toBe([]);
});

// The two endings are different states and the sentences differ with them. A
// rollback really did leave the data alone; a stranded epoch is committed over
// rows whose keyring never landed, and telling that reader "no changes made"
// is the app describing the one outcome it did not have.
it('does not tell a stranded reader that nothing changed', function (): void {
    $user = settingsStrandedUser('settings-stranded-copy');
    test()->actingAs($user);

    app()->instance(EncryptionMigrationService::class, settingsStrandedMigrationService(
        new StrandedEncryptionEpochException(SETTINGS_STRANDED_MESSAGE),
    ));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('showEncryptionModal', true)
        ->call('enableEncryption')
        ->assertSet('encryptionStep', EncryptionSetupStep::Stranded->value)
        ->assertSee(Lang::get('mobile::pairing.encryption_incomplete'))
        ->assertDontSee(Lang::get('sync::devices.encryption_failed_body'))
        ->assertDontSee(Lang::get('sync::devices.close_no_changes'));
});

it('still tells a rolled-back reader that nothing changed, because nothing did', function (): void {
    $user = settingsStrandedUser('settings-rollback-copy');
    test()->actingAs($user);

    app()->instance(EncryptionMigrationService::class, settingsStrandedMigrationService(
        new RuntimeException('The migration could not start.'),
    ));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('showEncryptionModal', true)
        ->call('enableEncryption')
        ->assertSet('encryptionStep', EncryptionSetupStep::Error->value)
        ->assertSee(Lang::get('sync::devices.encryption_failed_body'))
        ->assertDontSee(Lang::get('mobile::pairing.encryption_incomplete'));
});
