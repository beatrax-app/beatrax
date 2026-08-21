<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

// Under an encrypted user, counterparties.display_name is ciphertext at rest:
// the picker has to decrypt it, and it cannot sort on the stored column.

function rbddUser(): User
{
    return User::query()->create([
        'username' => 'rbdd-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function rbddEncryptedCounterparty(User $user, Session $session, string $slug, string $displayName): int
{
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $ciphertext = $codec->encryptValue('counterparties', 'display_name', $displayName, (int) $user->id, $session);

    expect($ciphertext)->not->toBe($displayName);

    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $ciphertext,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('ReportBuilder renders decrypted counterparty names in availableCounterparties (no ciphertext ORDER BY)', function (): void {
    $user = rbddUser();
    $session = $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    rbddEncryptedCounterparty($user, $session, 'rbdd-zebra', 'Zebra Traders');
    rbddEncryptedCounterparty($user, $session, 'rbdd-alpha', 'Alpha Traders');

    $component = Livewire::test(ReportBuilder::class);

    /** @var list<array{id: int, name: string}> $available */
    $available = $component->viewData('availableCounterparties');
    $names = array_column($available, 'name');

    expect($names)->toContain('Zebra Traders');
    expect($names)->toContain('Alpha Traders');

    $stored = DB::table('counterparties')->where('user_id', $user->id)->pluck('display_name')->all();
    foreach ($stored as $cipher) {
        expect($names)->not->toContain($cipher);
    }
});
