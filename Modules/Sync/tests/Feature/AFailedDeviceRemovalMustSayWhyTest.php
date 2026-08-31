<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

uses(RefreshDatabase::class);

// removeDevice() caught \Throwable with no bound variable and no log call.
// GdkRotationService writes the keyring FILE outside its SQL transaction, so a
// failure part-way can leave that file and current_epoch disagreeing — and the
// only trace of it was a flash message that names no cause.

beforeEach(function (): void {
    $this->recorded = [];

    $this->app->instance(LoggerInterface::class, new class($this->recorded) implements LoggerInterface
    {
        use LoggerTrait;

        /**
         * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $recorded
         */
        public function __construct(public array &$recorded) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->recorded[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    });
});

it('records why a device removal failed instead of discarding the exception', function (): void {
    $user = User::query()->create([
        'username' => 'removal-failure-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // No local identity and no unlocked app-lock KEK, so rotateAndRevoke()
    // throws before it touches device_registry.
    $peerId = (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'peer-that-cannot-be-removed',
        'name' => 'Old Laptop',
        'ed25519_public_key_hex' => str_repeat('c', 64),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->call('startRemove', $peerId)
        ->call('removeDevice')
        ->assertSet('showRemoveModal', false);

    $errors = array_values(array_filter(
        $this->recorded,
        static fn (array $line): bool => $line['level'] === 'error'
            && str_contains($line['message'], 'device removal failed'),
    ));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['context'])->toHaveKeys(['device_registry_id', 'reason', 'sqlstate'])
        ->and($errors[0]['context']['device_registry_id'])->toBe($peerId)
        ->and($errors[0]['context']['reason'])->toBeString()->not->toBe('');
});
